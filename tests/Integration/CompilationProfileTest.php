<?php

use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Exceptions\ResourceLimitException;
use Keepsuit\Liquid\Exceptions\TemplateSecurityException;
use Keepsuit\Liquid\FileSystems\ArrayFileSystem;
use Keepsuit\Liquid\Render\ResourceLimits;

test('unrestricted compiled artifacts reject bounded contexts before execution', function (bool $restricted, bool $streaming, string $source) {
    $environment = EnvironmentFactory::new()->setTemplatesCache(testCompiledTemplatesCache())->build();
    $template = testParseString($environment, $source);
    $warmContext = $environment->newRenderContext(data: ['text' => 'abc']);
    for ($i = 0; $i < 1000; $i++) {
        if ($template->render($warmContext) !== ($source === '' ? '' : 'abc')) {
            throw new RuntimeException('Warm output changed.');
        }
    }
    $context = $restricted
        ? EnvironmentFactory::forUntrustedTemplates()->build()->newRenderContext(data: ['text' => 'abc'])
        : $environment->newRenderContext(data: ['text' => 'abc'], resourceLimits: new ResourceLimits(intermediateBytesLimit: 4));

    expect(fn () => $streaming ? iterator_to_array($template->stream($context)) : $template->render($context))
        ->toThrow(TemplateSecurityException::class, 'bounded');
})->with([false, true])->with([false, true])->with(['', '{{ text }}']);

test('shared compiled caches distinguish bounded and unrestricted compilation', function () {
    $cache = testCompiledTemplatesCache();
    $sources = new ArrayFileSystem(['page' => '{% capture message %}{{ text }}{% endcapture %}{{ message }}']);
    $plain = EnvironmentFactory::new()->setFilesystem($sources)->setTemplatesCache($cache)->build();
    $bounded = EnvironmentFactory::new()->setFilesystem($sources)->setTemplatesCache($cache)
        ->setResourceLimits(new ResourceLimits(intermediateBytesLimit: 4))->build();
    $plainTemplate = $plain->parseTemplate('page');
    $boundedTemplate = $bounded->parseTemplate('page');

    expect($boundedTemplate)->not->toBe($plainTemplate);
    expect($plainTemplate->render($plain->newRenderContext(data: ['text' => '12345'])))->toBe('12345');
    expect(fn () => $boundedTemplate->render($bounded->newRenderContext(data: ['text' => '12345'])))
        ->toThrow(ResourceLimitException::class);
    expect($boundedTemplate->render($bounded->newRenderContext(data: ['text' => '123'])))->toBe('123');
    expect(implode('', iterator_to_array($boundedTemplate->stream($bounded->newRenderContext(data: ['text' => '123'])))))->toBe('123');
    expect($plain->parseTemplate('page'))->toBe($plainTemplate);
});

test('guarded compiled captures use fresh request allocation limits', function () {
    $environment = EnvironmentFactory::forUntrustedTemplates()->setTemplatesCache(testCompiledTemplatesCache())->build();
    $template = testParseString($environment, '{% capture message %}{{ text }}{% endcapture %}{{ message }}');
    $context = fn (?int $bytes) => $environment->newRenderContext(data: ['text' => '12345'], resourceLimits: new ResourceLimits(intermediateBytesLimit: $bytes));

    expect($template->render($context(null)))->toBe('12345');
    expect(fn () => $template->render($context(4)))->toThrow(ResourceLimitException::class);
    expect($template->render($context(5)))->toBe('12345');
    expect(fn () => iterator_to_array($template->stream($context(4))))->toThrow(ResourceLimitException::class);
    expect(implode('', iterator_to_array($template->stream($context(5)))))->toBe('12345');
});

test('legacy compiled artifacts cannot claim bounded execution support', function () {
    $legacy = new class extends Keepsuit\Liquid\Compiler\CompiledTemplate
    {
        public function name(): ?string
        {
            return 'legacy';
        }

        protected function renderCompiledString(Keepsuit\Liquid\Render\RenderContext $context): string
        {
            throw new RuntimeException('Legacy body must not execute.');
        }

        protected function renderCompiled(Keepsuit\Liquid\Render\RenderContext $context): iterable
        {
            throw new RuntimeException('Legacy body must not execute.');
        }
    };
    $environment = EnvironmentFactory::forUntrustedTemplates()->build();
    expect(fn () => $legacy->render($environment->newRenderContext()))->toThrow(TemplateSecurityException::class);
    expect(fn () => iterator_to_array($legacy->stream($environment->newRenderContext())))->toThrow(TemplateSecurityException::class);
});

test('unspecified and legacy template states retain guarded compilation', function (bool $legacy) {
    $environment = EnvironmentFactory::new()->setTemplatesCache(testCompiledTemplatesCache())->build();
    $state = new Keepsuit\Liquid\TemplateSharedState;
    if ($legacy) {
        $class = $state::class;
        $serialized = 'O:'.strlen($class).':"'.$class.'":3:{';
        foreach (['errors' => [], 'partials' => [], 'outputs' => $state->outputs] as $key => $value) {
            $serialized .= serialize($key).serialize($value);
        }
        $state = unserialize($serialized.'}', ['allowed_classes' => [Keepsuit\Liquid\TemplateSharedState::class, Keepsuit\Liquid\Support\OutputsBag::class]]);
    }
    $parsed = new Keepsuit\Liquid\ParsedTemplate($environment->parseString('{{ text }}')->root, $state);
    $path = testTemporaryDirectory()->path('manual.php');
    $environment->compile($parsed, $path);
    $template = require $path;
    expect($template->render($environment->newRenderContext(data: ['text' => 'abc'], resourceLimits: new ResourceLimits(intermediateBytesLimit: 3))))->toBe('abc');
    expect(fn () => $template->render($environment->newRenderContext(data: ['text' => 'abcd'], resourceLimits: new ResourceLimits(intermediateBytesLimit: 3))))
        ->toThrow(ResourceLimitException::class);
})->with([false, true]);
