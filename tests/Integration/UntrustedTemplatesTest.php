<?php

use Keepsuit\Liquid\Contracts\ProvidesCollectionSlice;
use Keepsuit\Liquid\Drop;
use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Exceptions\FileSystemException;
use Keepsuit\Liquid\Exceptions\ResourceLimitException;
use Keepsuit\Liquid\Exceptions\TemplateSecurityException;
use Keepsuit\Liquid\FileSystems\ArrayFileSystem;
use Keepsuit\Liquid\Render\ResourceLimits;
use Keepsuit\Liquid\Security\TemplatePolicy;

final class UntrustedSecretObject
{
    public static int $calls = 0;

    public function __get(string $name): mixed
    {
        self::$calls++;

        return 'secret';
    }

    public function __toString(): string
    {
        self::$calls++;

        return 'secret';
    }
}

final class ApprovedProductDrop extends Drop
{
    public function title(): string
    {
        return 'Bag';
    }

    public function unexpected(): object
    {
        return new UntrustedSecretObject;
    }
}

final class ApprovedEndlessCollection implements IteratorAggregate
{
    public int $visits = 0;

    public function getIterator(): Traversable
    {
        while (true) {
            $this->visits++;
            yield 'x';
        }
    }
}

final class ApprovedBoundedProducts implements ProvidesCollectionSlice
{
    public ?int $requested = null;

    public function liquidSlice(int $offset, ?int $length): array
    {
        $this->requested = $length;

        return array_fill(0, $length ?? 1000000, ['title' => 'Bag']);
    }
}

describe('untrusted templates across execution backends', function () {
    test('rejects ranges before allocating their selected segment', function (bool $compiled, bool $stream, string $source) {
        $environment = EnvironmentFactory::forUntrustedTemplates()->setFilesystem(new ArrayFileSystem(['item' => '{{ n }}']))
            ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
        $template = testParseString($environment, $source);
        $context = $environment->newRenderContext();
        $peak = memory_get_usage();
        expect(fn () => $stream ? iterator_to_array($template->stream($context)) : $template->render($context))
            ->toThrow(ResourceLimitException::class);
        expect(memory_get_usage() - $peak)->toBeLessThan(2_000_000);
    })->with([false, true])->with([false, true])->with([
        '{% for n in (1..1000000000) %}x{% endfor %}',
        '{% tablerow n in (1..1000000000) %}x{% endtablerow %}',
        '{% render "item" for (1..1000000000) as n %}',
    ]);

    test('keeps limited huge ranges cheap and compatible', function (bool $compiled) {
        $environment = EnvironmentFactory::forUntrustedTemplates()
            ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
        expect(testParseString($environment, '{% for n in (1..1000000000) limit: 3 %}{{ n }}{% endfor %}')
            ->render($environment->newRenderContext()))->toBe('123');
    })->with([false, true]);

    test('bounds nested captures, filters, arrays and cumulative output', function (bool $compiled, bool $stream, string $source) {
        $environment = EnvironmentFactory::forUntrustedTemplates()->setResourceLimits(new ResourceLimits(
            intermediateBytesLimit: 1024, collectionSizeLimit: 100, cumulativeRenderScoreLimit: 10000,
        ))->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
        $template = testParseString($environment, $source);
        $context = $environment->newRenderContext();
        expect(fn () => $stream ? iterator_to_array($template->stream($context)) : $template->render($context))
            ->toThrow(ResourceLimitException::class);
    })->with([false, true])->with([false, true])->with([
        '{% assign s = "xxxxxxxxxxxxxxxx" %}{% for n in (1..20) %}{% capture s %}{{ s }}{{ s }}{% endcapture %}{% endfor %}',
        '{{ "'.str_repeat('a', 64).'" | replace: "a", "'.str_repeat('b', 64).'" }}',
        '{{ "'.str_repeat('a', 128).'" | split: "" | join: "," }}',
        '{% for n in (1..100) %}'.str_repeat('x', 32).'{% endfor %}',
        '{% tablerow n in (1..100) %}'.str_repeat('x', 32).'{% endtablerow %}',
    ]);

    test('rejects PHP capabilities before triggering any callback', function (bool $compiled, string $source) {
        UntrustedSecretObject::$calls = 0;
        $environment = EnvironmentFactory::forUntrustedTemplates()
            ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
        $template = testParseString($environment, $source);
        expect(fn () => $template->render($environment->newRenderContext(data: ['value' => new UntrustedSecretObject])))
            ->toThrow(TemplateSecurityException::class);
        expect(UntrustedSecretObject::$calls)->toBe(0);
    })->with([false, true])->with(['{{ value }}', '{{ value.secret }}', '{{ value | append: "" }}', '{{ value | map: "secret" }}']);

    test('supports explicitly approved Drops but rejects unexpected returned objects', function (bool $compiled) {
        $environment = EnvironmentFactory::forUntrustedTemplates(new TemplatePolicy(allowedClasses: [ApprovedProductDrop::class]))
            ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
        expect(testParseString($environment, '{{ product.title }}')->render($environment->newRenderContext(data: ['product' => new ApprovedProductDrop])))
            ->toBe('Bag');
        expect(fn () => testParseString($environment, '{{ product.unexpected }}')->render($environment->newRenderContext(data: ['product' => new ApprovedProductDrop])))
            ->toThrow(TemplateSecurityException::class);
    })->with([false, true]);

    test('stops endless approved iterators during materialization', function (bool $compiled, string $source) {
        $environment = EnvironmentFactory::forUntrustedTemplates(new TemplatePolicy(allowedClasses: [ApprovedEndlessCollection::class]))
            ->setResourceLimits(new ResourceLimits(collectionSizeLimit: 10, renderTimeLimitMilliseconds: 1000))
            ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
        $values = new ApprovedEndlessCollection;
        expect(fn () => testParseString($environment, $source)->render($environment->newRenderContext(data: ['values' => $values])))
            ->toThrow(ResourceLimitException::class);
        expect($values->visits)->toBeLessThanOrEqual(11);
    })->with([false, true])->with(['{% for v in values %}{{ v }}{% endfor %}', '{{ values | sort }}', '{{ values | size }}', '{{ values | slice: 0, 3 }}']);
});

test('restricted parsing bounds source, token count and transitive snippets', function () {
    $environment = EnvironmentFactory::forUntrustedTemplates(new TemplatePolicy(maxSourceBytes: 100, maxTokens: 10))->build();
    expect(fn () => $environment->parseString(str_repeat('x', 101)))->toThrow(ResourceLimitException::class);
    expect(fn () => $environment->parseString(str_repeat('{{ a }}', 4)))->toThrow(ResourceLimitException::class);

    $environment = EnvironmentFactory::forUntrustedTemplates(new TemplatePolicy(maxTokens: 9))->setFilesystem(new ArrayFileSystem([
        'root' => '{% render "child" %}', 'child' => '{{ a }}{{ b }}',
    ]))->build();
    expect(fn () => $environment->parseTemplate('root'))->toThrow(ResourceLimitException::class);
});

test('authorized snippet snapshots never fall back to other files', function () {
    $environment = EnvironmentFactory::forUntrustedTemplates()->setFilesystem(new ArrayFileSystem(['root' => '{% render "other-tenant" %}']))->build();
    expect(fn () => $environment->parseTemplate('root'))->toThrow(FileSystemException::class);
});

test('recursive input is rejected without invoking application code', function () {
    $data = [];
    $data['cycle'] = &$data;
    $environment = EnvironmentFactory::forUntrustedTemplates()->build();
    expect(fn () => $environment->newRenderContext(data: $data))->toThrow(TemplateSecurityException::class);
});

test('unlimited provider loops issue a bounded probe before rejecting excess rows', function (bool $compiled) {
    $products = new ApprovedBoundedProducts;
    $environment = EnvironmentFactory::forUntrustedTemplates(new TemplatePolicy(allowedClasses: [ApprovedBoundedProducts::class]))
        ->setResourceLimits(new ResourceLimits(collectionSizeLimit: 10))
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    expect(fn () => testParseString($environment, '{% for p in products %}{{ p.title }}{% endfor %}')
        ->render($environment->newRenderContext(data: ['products' => $products])))->toThrow(ResourceLimitException::class);
    expect($products->requested)->toBe(11);
    expect(testParseString($environment, '{% for p in products limit: 2 %}{{ p.title }}{% endfor %}')
        ->render($environment->newRenderContext(data: ['products' => $products])))->toBe('BagBag');
    expect($products->requested)->toBe(2);
})->with([false, true]);

test('large range inspection does not materialize the range', function (bool $compiled) {
    $environment = EnvironmentFactory::forUntrustedTemplates()
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    expect(testParseString($environment, '{{ (1..1000000000) | first }}|{{ (1..1000000000) | last }}|{{ (1..1000000000) | size }}|{{ (1..1000000000) | slice: 2, 3 | join: "," }}')
        ->render($environment->newRenderContext()))->toBe('1|1000000000|1000000000|3,4,5');
})->with([false, true]);

test('a template cannot enable PHP functions or invent executable tags', function (bool $compiled, string $source) {
    $environment = EnvironmentFactory::forUntrustedTemplates()
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    expect(fn () => testParseString($environment, $source)->render($environment->newRenderContext()))
        ->toThrow(Keepsuit\Liquid\Exceptions\LiquidException::class);
})->with([false, true])->with(['{{ "id" | system }}', '{% php %}echo 1;{% endphp %}', '{% include "secret.php" %}']);
