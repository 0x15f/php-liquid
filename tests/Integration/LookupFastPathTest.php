<?php

use Keepsuit\Liquid\Exceptions\UndefinedVariableException;

test('array lookup fast path retains Liquid edge semantics', function (bool $compiled) {
    $data = ['item' => [
        'title' => 'Bag', 'present_null' => null, 'size' => null,
        'items' => [['title' => 'first'], ['title' => 'last']],
        'hash' => ['first' => 'literal key'],
    ], 'key' => 'title'];
    $source = '{{ item.title }}|{{ item[key] }}|{{ item.present_null | default: "nil" }}|{{ item.size | default: "null-size" }}|{{ item.items[-1].title }}|{{ item.items.first.title }}|{{ item.hash["first"] }}';
    foreach (['render', 'stream'] as $method) {
        $environment = testEnvironmentFactory($compiled)->setStrictVariables(true)->setRethrowErrors(true)->build();
        $template = testParseString($environment, $source);
        $context = $environment->newRenderContext(data: $data);
        $output = $method === 'render' ? $template->render($context) : implode('', iterator_to_array($template->stream($context)));
        expect($output)->toBe('Bag|Bag|nil|null-size|last|first|literal key');
    }
})->with('template backends');

test('array fast path normalizes deferred objects exactly once', function (bool $compiled) {
    $calls = 0;
    $environment = testEnvironmentFactory($compiled)->setRethrowErrors(true)->build();
    $template = testParseString($environment, '{{ item.lazy.title }}|{{ item.lazy.title }}');
    $context = $environment->newRenderContext(data: ['item' => ['lazy' => function () use (&$calls) {
        $calls++;

        return ['title' => 'computed'];
    }]]);
    expect($template->render($context))->toBe('computed|computed');
    expect($calls)->toBe(1);
})->with('template backends');

test('array fast path distinguishes missing and null in strict mode', function (bool $compiled) {
    $environment = testEnvironmentFactory($compiled)->setStrictVariables(true)->setRethrowErrors(true)->build();
    expect(testParseString($environment, '{{ item.value }}')->render($environment->newRenderContext(data: ['item' => ['value' => null]])))->toBe('');
    expect(fn () => testParseString($environment, '{{ item.missing }}')->render($environment->newRenderContext(data: ['item' => ['value' => null]])))
        ->toThrow(UndefinedVariableException::class);
})->with('template backends');
