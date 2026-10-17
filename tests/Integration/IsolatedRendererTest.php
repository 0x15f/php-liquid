<?php

use Keepsuit\Liquid\Exceptions\IsolatedRenderException;
use Keepsuit\Liquid\Exceptions\ResourceLimitException;
use Keepsuit\Liquid\Exceptions\TemplateSecurityException;
use Keepsuit\Liquid\Security\IsolatedRenderer;

test('a reusable isolated worker compiles snippets and keeps personalized requests separate', function () {
    $renderer = new IsolatedRenderer(timeoutMilliseconds: 3000, maxRequests: 2);
    $templates = ['page' => '<h1>{{ name | escape }}</h1>{% render "card", name: name %}', 'card' => '{{ name }}'];
    try {
        expect($renderer->render($templates, 'page', ['name' => 'A&B'], 'tenant-a'))->toBe('<h1>A&amp;B</h1>A&B');
        expect($renderer->render($templates, 'page', ['name' => 'Bob'], 'tenant-a'))->toBe('<h1>Bob</h1>Bob');
        // A third request crosses the worker-recycling boundary.
        expect($renderer->render($templates, 'page', ['name' => 'Carol'], 'tenant-a'))->toBe('<h1>Carol</h1>Carol');
        expect($renderer->render(['page' => 'Other tenant'], 'page', [], 'tenant-b'))->toBe('Other tenant');
        expect($renderer->render($templates, 'page', ['name' => 'A&B'], 'tenant-a'))->toBe('<h1>A&amp;B</h1>A&B');
    } finally {
        $renderer->close();
    }
});

test('isolated failures do not poison the next render', function () {
    $renderer = new IsolatedRenderer(timeoutMilliseconds: 3000);
    try {
        expect(fn () => $renderer->render(['page' => '{% for x in (1..1000000000) %}x{% endfor %}'], 'page'))
            ->toThrow(ResourceLimitException::class);
        expect(fn () => $renderer->render(['page' => '{% render "not-authorized" %}'], 'page'))
            ->toThrow(IsolatedRenderException::class);
        expect($renderer->render(['page' => 'recovered'], 'page'))->toBe('recovered');
        expect(fn () => $renderer->render(['page' => '{{ object }}'], 'page', ['object' => new stdClass]))
            ->toThrow(TemplateSecurityException::class);
    } finally {
        $renderer->close();
    }
});

test('a hard parent deadline terminates the worker promptly', function () {
    $renderer = new IsolatedRenderer(timeoutMilliseconds: 1);
    $start = hrtime(true);
    try {
        expect(fn () => $renderer->render(['page' => str_repeat('{{ x }}', 6000)], 'page'))
            ->toThrow(ResourceLimitException::class);
        expect((hrtime(true) - $start) / 1_000_000)->toBeLessThan(2000);
    } finally {
        $renderer->close();
    }
});

test('PHP source stays literal inside compiled user templates', function () {
    $renderer = new IsolatedRenderer(timeoutMilliseconds: 3000);
    $source = '<?php throw new RuntimeException("executed"); ?><?= shell_exec("id") ?>';
    try {
        expect($renderer->render(['page' => $source], 'page'))->toBe($source);
        expect($renderer->render(['page' => '{{ "%FF%00%0A" | url_decode }}'], 'page'))->toBe("\xff\0\n");
    } finally {
        $renderer->close();
    }
});

test('a worker memory failure leaves the parent alive and the renderer recovers', function () {
    $renderer = new IsolatedRenderer(timeoutMilliseconds: 5000, memoryMegabytes: 32);
    try {
        expect(fn () => $renderer->render(['page' => str_repeat('{{ x }}', 6000)], 'page', ['x' => 'a']))
            ->toThrow(ResourceLimitException::class, 'memory allowance');
        expect($renderer->render(['page' => 'recovered'], 'page'))->toBe('recovered');
    } finally {
        $renderer->close();
    }
});

test('isolated streaming preserves bytes and discards cancelled requests', function () {
    $renderer = new IsolatedRenderer(timeoutMilliseconds: 3000);
    try {
        $templates = ['page' => '{% for x in (1..1000) %}{{ text }}{% endfor %}'];
        $stream = $renderer->stream($templates, 'page', ['text' => str_repeat('x', 4096)]);
        expect($stream->current())->toBe(str_repeat('x', 4096));
        expect(fn () => $renderer->render(['page' => 'overlap'], 'page'))->toThrow(IsolatedRenderException::class, 'overlapping');
        unset($stream);
        expect($renderer->render(['page' => 'after cancellation'], 'page'))->toBe('after cancellation');
        $chunks = iterator_to_array($renderer->stream(['page' => '{{ "%FF%00%0A" | url_decode }}'], 'page'));
        expect(implode('', $chunks))->toBe("\xff\0\n");
    } finally {
        $renderer->close();
    }
});

test('an isolated stream can emit a prefix then fail and recover', function () {
    $renderer = new IsolatedRenderer(timeoutMilliseconds: 3000);
    $received = '';
    try {
        expect(function () use ($renderer, &$received) {
            foreach ($renderer->stream(['page' => str_repeat('x', 4096).'{{ "id" | system }}'], 'page') as $chunk) {
                $received .= $chunk;
            }
        })->toThrow(IsolatedRenderException::class);
        expect($received)->toBe(str_repeat('x', 4096));
        expect($renderer->render(['page' => 'recovered'], 'page'))->toBe('recovered');
    } finally {
        $renderer->close();
    }
});

test('isolated streaming enforces the total output allowance without buffering it', function () {
    $renderer = new IsolatedRenderer(timeoutMilliseconds: 5000, memoryMegabytes: 32);
    $bytes = 0;
    try {
        expect(function () use ($renderer, &$bytes) {
            foreach ($renderer->stream(['page' => '{% for x in (1..3000) %}{{ text }}{% endfor %}'], 'page', ['text' => str_repeat('x', 4096)]) as $chunk) {
                $bytes += strlen($chunk);
            }
        })->toThrow(ResourceLimitException::class);
        expect($bytes)->toBeGreaterThan(9_990_000)->toBeLessThanOrEqual(10_000_000);
    } finally {
        $renderer->close();
    }
});

test('the isolated parent deadline includes consumer backpressure', function () {
    $renderer = new IsolatedRenderer(timeoutMilliseconds: 1000);
    try {
        $stream = $renderer->stream(['page' => '{% for x in (1..1000) %}{{ text }}{% endfor %}'], 'page', ['text' => str_repeat('x', 4096)]);
        expect($stream->current())->toBe(str_repeat('x', 4096));
        usleep(1_100_000);
        expect(fn () => $stream->next())->toThrow(ResourceLimitException::class, 'wall-clock');
        unset($stream);
        expect($renderer->render(['page' => 'recovered'], 'page'))->toBe('recovered');
    } finally {
        $renderer->close();
    }
});

test('worker snapshot churn has bounded private-cache disk usage', function () {
    $renderer = new IsolatedRenderer(timeoutMilliseconds: 3000);
    $size = static function (string $directory): int {
        $bytes = 0;
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
            $bytes += $file->getSize();
        }

        return $bytes;
    };
    try {
        $source = str_repeat('x', 8192);
        expect($renderer->render(['page' => $source], 'page'))->toBe($source);
        $directory = invade($renderer)->directory;
        $initial = $size($directory);
        for ($i = 0; $i < 20; $i++) {
            expect($renderer->render(['page' => $source.$i], 'page'))->toBe($source.$i);
        }
        expect($size($directory))->toBeLessThan($initial * 6);
    } finally {
        $renderer->close();
    }
});

test('isolated date filters use the caller timezone for every request', function () {
    $timezone = date_default_timezone_get();
    $renderer = new IsolatedRenderer(timeoutMilliseconds: 3000);
    try {
        date_default_timezone_set('America/New_York');
        expect($renderer->render(['page' => '{{ 0 | date: "%z" }}'], 'page'))->toBe('-0500');
        date_default_timezone_set('UTC');
        expect(implode('', iterator_to_array($renderer->stream(['page' => '{{ 0 | date: "%z" }}'], 'page'))))->toBe('+0000');
    } finally {
        $renderer->close();
        date_default_timezone_set($timezone);
    }
});

test('JSON expansion is rejected before allocating a large parent payload', function () {
    $renderer = new IsolatedRenderer;
    $data = ['text' => str_repeat("\0", 2_000_000)];
    $before = memory_get_usage();
    memory_reset_peak_usage();
    expect(fn () => $renderer->render(['page' => '{{ text }}'], 'page', $data))
        ->toThrow(ResourceLimitException::class, 'JSON expansion');
    expect(memory_get_peak_usage() - $before)->toBeLessThan(5_000_000);
    expect(fn () => $renderer->render(['page' => 'x'], 'page', ['range' => new Keepsuit\Liquid\Nodes\Range(1, 2)]))
        ->toThrow(TemplateSecurityException::class, 'JSON values');
    $renderer->close();
});
