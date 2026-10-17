<?php

use Keepsuit\Liquid\Exceptions\ResourceLimitException;
use Keepsuit\Liquid\Render\ResourceLimits;

test('a cached template does not carry render outputs into the next request', function (bool $compiled) {
    $environment = testEnvironmentFactory($compiled)->build();
    $template = testParseString($environment, '{{ customer }}');
    $first = $environment->newRenderContext(data: ['customer' => 'Alice']);
    $first->getOutputs()->set('customer_private', 'alice@example.com');

    expect($template->render($first))->toBe('Alice');

    $second = $environment->newRenderContext(data: ['customer' => 'Bob']);
    expect($template->render($second))->toBe('Bob');
    expect($second->getOutputs()->get('customer_private'))->toBeNull();

    $third = $environment->newRenderContext(data: ['customer' => 'Carol']);
    $third->getOutputs()->set('customer_private', 'carol@example.com');
    expect(implode('', iterator_to_array($template->stream($third))))->toBe('Carol');
    $fourth = $environment->newRenderContext(data: ['customer' => 'Dave']);
    expect(implode('', iterator_to_array($template->stream($fourth))))->toBe('Dave');
    expect($fourth->getOutputs()->get('customer_private'))->toBeNull();
})->with('template backends');

test('empty loop work consumes the render budget', function (bool $compiled) {
    $environment = testEnvironmentFactory($compiled)
        ->setResourceLimits(new ResourceLimits(renderScoreLimit: 10))
        ->setRethrowErrors(true)
        ->build();
    $template = testParseString($environment, '{% for i in (1..1000) %}{% endfor %}');

    expect(fn () => $template->render($environment->newRenderContext()))
        ->toThrow(ResourceLimitException::class);
    expect(fn () => iterator_to_array($template->stream($environment->newRenderContext())))
        ->toThrow(ResourceLimitException::class);
})->with('template backends');
