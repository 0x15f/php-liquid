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
