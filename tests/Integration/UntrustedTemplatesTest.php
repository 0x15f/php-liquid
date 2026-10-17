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

    public function recursive(): array
    {
        $data = [];
        $data['cycle'] = &$data;

        return $data;
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

final class ApprovedSlowRecordDrop extends Drop
{
    public int $calls = 0;

    public function nextRecord(): self
    {
        $this->calls++;
        usleep(20000);

        return $this;
    }
}

final class ApprovedBudgetedObject
{
    public function __isset(string $name): bool
    {
        throw new Keepsuit\Liquid\Exceptions\DataLoadingLimitException('Query budget exceeded.');
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

test('hostile Drop property names cannot grow process-wide lookup caches indefinitely', function () {
    $environment = EnvironmentFactory::forUntrustedTemplates(new TemplatePolicy(allowedClasses: [ApprovedProductDrop::class]))->build();
    $context = $environment->newRenderContext(data: ['product' => new ApprovedProductDrop]);
    for ($i = 0; $i < 1500; $i++) {
        $context->get('product.unknown_property_'.$i);
    }
    $before = memory_get_usage();
    for ($i = 1500; $i < 12000; $i++) {
        $context->get('product.unknown_property_'.$i);
    }
    expect(memory_get_usage() - $before)->toBeLessThan(1_000_000);
    expect($context->get('product.title'))->toBe('Bag');
});

test('recursive values returned by approved adapters cannot enter assignment or comparison', function (bool $compiled, string $source) {
    $environment = EnvironmentFactory::forUntrustedTemplates(new TemplatePolicy(allowedClasses: [ApprovedProductDrop::class]))
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    expect(fn () => testParseString($environment, $source)->render($environment->newRenderContext(data: ['product' => new ApprovedProductDrop])))
        ->toThrow(TemplateSecurityException::class);
})->with([false, true])->with(['{% assign value = product.recursive %}', '{% if product.recursive contains product.recursive %}x{% endif %}']);

test('hostile expression and conditional nesting is rejected before compiling PHP', function (string $source) {
    expect(fn () => EnvironmentFactory::forUntrustedTemplates()->build()->parseString($source))
        ->toThrow(Keepsuit\Liquid\Exceptions\LiquidException::class);
})->with([
    '{{ '.str_repeat('a[', 101).'"x"'.str_repeat(']', 101).' }}',
    '{% if '.implode(' or ', array_fill(0, 101, 'false')).' %}x{% endif %}',
]);

test('restricted conditions never inherit application-wide operator callbacks', function (bool $compiled) {
    $property = new ReflectionProperty(Keepsuit\Liquid\Condition\Condition::class, 'customOperators');
    $previous = $property->getValue();
    Keepsuit\Liquid\Condition\Condition::registerOperator('==', fn () => throw new RuntimeException('privileged callback'));
    try {
        $environment = EnvironmentFactory::forUntrustedTemplates()
            ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
        expect(testParseString($environment, '{% if 1 == 1 %}safe{% endif %}')->render($environment->newRenderContext()))->toBe('safe');
    } finally {
        $property->setValue(null, $previous);
    }
})->with([false, true]);

test('date formats are bounded before native formatting allocates the expanded string', function (bool $compiled) {
    $environment = EnvironmentFactory::forUntrustedTemplates()
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    $template = testParseString($environment, '{{ "2026-01-01" | date: format }}');
    $context = $environment->newRenderContext(data: ['format' => str_repeat('%c', 1_000_000)]);
    $before = memory_get_usage();
    memory_reset_peak_usage();
    expect(fn () => $template->render($context))->toThrow(ResourceLimitException::class);
    expect(memory_get_peak_usage() - $before)->toBeLessThan(5_000_000);
})->with([false, true]);

test('bounded array slices do not reject the unsliced input or allocate an oversized result', function (bool $compiled) {
    $environment = EnvironmentFactory::forUntrustedTemplates()->setResourceLimits(new ResourceLimits(collectionSizeLimit: 10))
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    $data = ['items' => array_fill(0, 20, 'x')];
    expect(testParseString($environment, '{% for x in items limit: 3 %}{{ x }}{% endfor %}')->render($environment->newRenderContext(data: $data)))->toBe('xxx');
    expect(testParseString($environment, '{{ items | slice: 0, 3 | join: "" }}')->render($environment->newRenderContext(data: $data)))->toBe('xxx');
    expect(fn () => testParseString($environment, '{{ items | slice: 0, 20 }}')->render($environment->newRenderContext(data: $data)))->toThrow(ResourceLimitException::class);
})->with([false, true]);

test('long adapter lookup chains check deadlines between PHP data accesses', function (bool $compiled) {
    $record = new ApprovedSlowRecordDrop;
    $environment = EnvironmentFactory::forUntrustedTemplates(new TemplatePolicy(allowedClasses: [ApprovedSlowRecordDrop::class]))
        ->setResourceLimits(new ResourceLimits(renderTimeLimitMilliseconds: 30))
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    $template = testParseString($environment, '{{ record'.str_repeat('.next_record', 20).' }}');
    expect(fn () => $template->render($environment->newRenderContext(data: ['record' => $record])))->toThrow(ResourceLimitException::class);
    expect($record->calls)->toBeLessThanOrEqual(3);
})->with([false, true]);

test('magic property probes cannot swallow provider loading-budget failures', function (bool $compiled) {
    $environment = EnvironmentFactory::forUntrustedTemplates(new TemplatePolicy(allowedClasses: [ApprovedBudgetedObject::class]))
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    expect(fn () => testParseString($environment, '{{ record.field }}')->render($environment->newRenderContext(data: ['record' => new ApprovedBudgetedObject])))
        ->toThrow(Keepsuit\Liquid\Exceptions\DataLoadingLimitException::class, 'Query budget');
})->with([false, true]);

test('hot render paths retain range allocation guards when inputs change', function (bool $compiled) {
    $environment = EnvironmentFactory::forUntrustedTemplates()
        ->setTemplatesCache($compiled ? testCompiledTemplatesCache() : new Keepsuit\Liquid\TemplatesCache\MemoryTemplatesCache)->build();
    $template = testParseString($environment, '{% for n in (1..end) %}x{% endfor %}');
    for ($i = 0; $i < 1000; $i++) {
        if ($template->render($environment->newRenderContext(data: ['end' => 3])) !== 'xxx') {
            throw new RuntimeException('Warm output changed.');
        }
    }
    $before = memory_get_usage();
    expect(fn () => $template->render($environment->newRenderContext(data: ['end' => 1000000000])))
        ->toThrow(ResourceLimitException::class);
    expect(memory_get_usage() - $before)->toBeLessThan(2_000_000);
})->with([false, true]);
