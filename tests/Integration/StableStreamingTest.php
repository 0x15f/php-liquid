<?php

use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Exceptions\ResourceLimitException;
use Keepsuit\Liquid\FileSystems\ArrayFileSystem;
use Keepsuit\Liquid\Render\ResourceLimits;

test('stable streams match Shopify reference bytes for personalized grids and nested snippets', function (bool $compiled, bool $restricted) {
    require_once dirname(__DIR__, 2).'/performance/comparison/fixtures.php';
    $fixtures = comparisonFixtures();
    $hashes = json_decode(file_get_contents(dirname(__DIR__, 2).'/performance/comparison/results/reference-hashes.json'), true, flags: JSON_THROW_ON_ERROR);
    $factory = $restricted ? EnvironmentFactory::forUntrustedTemplates() : EnvironmentFactory::new();
    $environment = $factory->setFilesystem(new ArrayFileSystem($fixtures['templates']))
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    foreach ($fixtures['workloads'] as $name => $requests) {
        foreach ($requests as $index => $request) {
            $template = $environment->parseTemplate($request['template']);
            $chunks = iterator_to_array($template->stream($environment->newRenderContext(data: $request['data'])));
            expect(array_keys($chunks))->toBe(range(0, count($chunks) - 1));
            foreach ($chunks as $chunk) {
                expect($chunk)->toBeString();
            }
            $output = implode('', $chunks);
            expect($output)->toBe($template->render($environment->newRenderContext(data: $request['data'])));
            expect(hash('sha256', $output))->toBe($hashes[$name][$index]);
        }
    }
})->with([false, true])->with([false, true]);

test('overlapping root executions cannot reset a stream output budget', function (bool $compiled) {
    $environment = EnvironmentFactory::forUntrustedTemplates()
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    $template = testParseString($environment, '{% for x in (1..4) %}{{ text }}{% endfor %}');
    $context = $environment->newRenderContext(data: ['text' => str_repeat('x', 4096)]);
    $stream = $template->stream($context);
    expect($stream->current())->toBe(str_repeat('x', 4096));
    expect(fn () => $template->render($context))->toThrow(ResourceLimitException::class, 'overlapping');
    $second = $template->stream($context);
    expect(fn () => $second->current())->toThrow(ResourceLimitException::class, 'overlapping');
    unset($second, $stream);
    expect($context->getRegister('for_stack'))->toBe([]);
    expect($template->render($context))->toBe(str_repeat('x', 16384));
})->with([false, true]);

test('a stream never yields bytes beyond its output allowance', function (bool $compiled) {
    $environment = EnvironmentFactory::forUntrustedTemplates()
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    $template = testParseString($environment, '{% for x in (1..20) %}{{ text }}{% endfor %}');
    $context = $environment->newRenderContext(data: ['text' => str_repeat('x', 4096)], resourceLimits: new ResourceLimits(renderLengthLimit: 8192));
    $received = '';
    expect(function () use ($template, $context, &$received) {
        foreach ($template->stream($context) as $chunk) {
            $received .= $chunk;
        }
    })->toThrow(ResourceLimitException::class);
    expect(strlen($received))->toBe(8192);
    expect($context->getRegister('for_stack'))->toBe([]);
})->with([false, true]);

test('streaming a large response avoids allocating the complete HTML string', function (bool $compiled) {
    $environment = EnvironmentFactory::forUntrustedTemplates()
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    $template = testParseString($environment, '{% for x in (1..2000) %}{{ text }}{% endfor %}');
    $data = ['text' => str_repeat('x', 4096)];
    $context = $environment->newRenderContext(data: $data);
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $output = $template->render($context);
    $renderPeak = memory_get_peak_usage() - $before;
    $expected = hash('sha256', $output);
    expect(strlen($output))->toBe(8_192_000);
    unset($output, $context);

    $context = $environment->newRenderContext(data: $data);
    $before = memory_get_usage();
    memory_reset_peak_usage();
    $hash = hash_init('sha256');
    $bytes = 0;
    foreach ($template->stream($context) as $chunk) {
        hash_update($hash, $chunk);
        $bytes += strlen($chunk);
    }
    $streamPeak = memory_get_peak_usage() - $before;
    expect($bytes)->toBe(8_192_000);
    expect(hash_final($hash))->toBe($expected);
    expect($streamPeak)->toBeLessThan((int) ($renderPeak / 4));
})->with([false, true]);
