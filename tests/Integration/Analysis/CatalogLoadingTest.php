<?php

use Keepsuit\Liquid\Analysis\DependencyPlanner;
use Keepsuit\Liquid\Exceptions\DataLoadingLimitException;
use Keepsuit\Liquid\Performance\Loading\CatalogSession;
use Keepsuit\Liquid\Performance\Loading\CatalogStore;
use Keepsuit\Liquid\Performance\Loading\ProductsCollection;
use Keepsuit\Liquid\Tests\Stubs\StubFileSystem;

require_once dirname(__DIR__, 3).'/performance/Loading/fixtures.php';

function loadingTestDatabase(): PDO
{
    static $path;
    if ($path === null) {
        $path = testTemporaryDirectory()->path('catalog.sqlite');
        createLoadingDatabase($path, 100);
    }

    return new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
}

function loadingTestEnvironment(bool $compiled, string $source): array
{
    $sources = loadingTemplates();
    $env = testEnvironmentFactory($compiled)->setFilesystem(new StubFileSystem($sources))
        ->setStrictVariables(true)->setStrictFilters(true)->setRethrowErrors(true)->build();
    $parsed = $env->parseString($source);
    $plan = (new DependencyPlanner(fn (string $name) => $env->parseString($sources[$name])))->analyse($parsed);

    return [$env, testParseString($env, $source), $plan];
}

test('planned fields and lazy relationships batch across the selected page only', function (bool $compiled) {
    [$env, $template, $plan] = loadingTestEnvironment($compiled, loadingTemplates()['grid']);
    foreach ([false, true] as $showVariants) {
        $outputs = [];
        foreach (['planned', 'eager_full', 'eager_projected', 'naive'] as $mode) {
            $store = new CatalogStore(loadingTestDatabase(), 1);
            $session = new CatalogSession($store, $plan, $mode);
            $outputs[$mode] = $template->render($env->newRenderContext(data: [
                'collection' => ['products' => new ProductsCollection($session)],
                'customer' => ['id' => 123, 'show_variants' => $showVariants],
            ]));
            if ($mode === 'planned') {
                expect($store->queries)->toBe($showVariants ? 2 : 1);
                expect($store->rows)->toBe($showVariants ? 120 : 24);
                expect($store->bytes)->toBeLessThan(12_000);
            }
            if ($mode === 'naive') {
                expect($store->queries)->toBe($showVariants ? 49 : 25);
            }
        }
        expect(array_unique($outputs))->toHaveCount(1);
    }
})->with('template backends');

test('dynamic fields batch lazily including null values and variant fields', function (bool $compiled) {
    [$env, $template, $plan] = loadingTestEnvironment($compiled, loadingTemplates()['dynamic']);
    $store = new CatalogStore(loadingTestDatabase(), 1);
    $session = new CatalogSession($store, $plan);
    $data = ['collection' => ['products' => new ProductsCollection($session)], 'field' => 'extra', 'variant_field' => 'extra'];
    $first = $template->render($env->newRenderContext(data: $data));
    expect($store->queries)->toBe(4); // page, nullable product field, variants, dynamic variant field
    expect($store->rows)->toBe(240);
    expect($template->render($env->newRenderContext(data: $data)))->toBe($first);
    expect($store->queries)->toBe(4);
})->with('template backends');

test('sessions isolate tenants and customers across repeated worker renders', function (bool $compiled) {
    [$env, $template, $plan] = loadingTestEnvironment($compiled, loadingTemplates()['grid']);
    $outputs = [];
    foreach ([1, 2, 1] as $tenant) {
        $store = new CatalogStore(loadingTestDatabase(), $tenant);
        $session = new CatalogSession($store, $plan);
        $outputs[] = $template->render($env->newRenderContext(data: [
            'collection' => ['products' => new ProductsCollection($session)],
            'customer' => ['id' => $tenant, 'show_variants' => $tenant === 2],
        ]));
    }
    expect($outputs[0])->toBe($outputs[2])->not->toContain('Tenant 2');
    expect($outputs[1])->toContain('Tenant 2')->not->toContain('Tenant 1');
})->with('template backends');

test('database collection preserves offsets negative lengths and repeat reads', function (bool $compiled) {
    $source = '{% for p in collection.products reversed offset: -3 limit: -1 %}{{ p.id }};{% endfor %}|{% for p in collection.products limit: 2 %}{{ p.id }};{% endfor %}{% for p in collection.products limit: 2 offset: continue %}{{ p.id }};{% endfor %}';
    [$env, $template, $plan] = loadingTestEnvironment($compiled, $source);
    $store = new CatalogStore(loadingTestDatabase(), 1);
    $session = new CatalogSession($store, $plan);
    expect($template->render($env->newRenderContext(data: ['collection' => ['products' => new ProductsCollection($session)]])))
        ->toBe('99;98;|1;2;3;4;');
    expect($store->queries)->toBe(4); // count once, then three bounded windows
})->with('template backends');

test('loading budgets fail explicitly before unbounded hydration', function () {
    [, , $plan] = loadingTestEnvironment(false, loadingTemplates()['grid']);
    $session = new CatalogSession(new CatalogStore(loadingTestDatabase(), 1), $plan);
    expect(fn () => $session->page(0, null))->toThrow(DataLoadingLimitException::class, 'Page loading budget');
    $rowStore = new CatalogStore(loadingTestDatabase(), 1, maxRows: 2);
    expect(fn () => $rowStore->products(0, 24, ['id']))->toThrow(DataLoadingLimitException::class, 'Row loading budget');
    $queryStore = new CatalogStore(loadingTestDatabase(), 1, maxQueries: 1);
    $queryStore->count();
    expect(fn () => $queryStore->count())->toThrow(DataLoadingLimitException::class, 'Query loading budget');
    $byteStore = new CatalogStore(loadingTestDatabase(), 1, maxBytes: 10);
    expect(fn () => $byteStore->products(0, 1, ['description']))->toThrow(DataLoadingLimitException::class, 'Byte loading budget');
    expect(fn () => $queryStore->products(0, 1, ['title; DROP TABLE products']))->toThrow(RuntimeException::class, 'Unknown projected field');
});

test('collection size and first last lookups avoid whole collection hydration', function (bool $compiled) {
    [$env, $template, $plan] = loadingTestEnvironment($compiled, '{{ collection.products.size }}|{{ collection.products.first.id }}|{{ collection.products.last.id }}');
    $store = new CatalogStore(loadingTestDatabase(), 1);
    $session = new CatalogSession($store, $plan);
    expect($template->render($env->newRenderContext(data: ['collection' => ['products' => new ProductsCollection($session)]])))
        ->toBe('100|1|100');
    expect($store->queries)->toBe(3);
    expect($store->rows)->toBe(2);
})->with('template backends');

test('global sort does not push a premature limit into SQL', function (bool $compiled) {
    [$env, $template, $plan] = loadingTestEnvironment($compiled, '{% assign sorted = collection.products | sort: "title" %}{% for p in sorted limit: 2 %}{{ p.title }}{% endfor %}');
    $store = new CatalogStore(loadingTestDatabase(), 1);
    $session = new CatalogSession($store, $plan);
    expect(fn () => $template->render($env->newRenderContext(data: ['collection' => ['products' => new ProductsCollection($session)]])))
        ->toThrow(DataLoadingLimitException::class, 'Page loading budget');
    expect($store->rows)->toBe(0);
})->with('template backends');

test('provider budgets abort even when ordinary Liquid errors are tolerated', function (bool $compiled) {
    $env = testEnvironmentFactory($compiled)->setRethrowErrors(false)->build();
    $template = testParseString($env, '{% assign sorted = collection.products | sort: "title" %}{{ sorted.size }}');
    $plan = new Keepsuit\Liquid\Analysis\DependencyPlan([], [], [], []);
    $store = new CatalogStore(loadingTestDatabase(), 1);
    $session = new CatalogSession($store, $plan);
    expect(fn () => $template->render($env->newRenderContext(data: ['collection' => ['products' => new ProductsCollection($session)]])))
        ->toThrow(DataLoadingLimitException::class, 'Page loading budget');
})->with('template backends');

test('collection bracket indexes fetch a single position and preserve strict missing errors', function (bool $compiled) {
    [$env, $template, $plan] = loadingTestEnvironment($compiled, '{{ collection.products[0].id }}|{{ collection.products[-1].id }}');
    $store = new CatalogStore(loadingTestDatabase(), 1);
    $session = new CatalogSession($store, $plan);
    $data = ['collection' => ['products' => new ProductsCollection($session)]];
    expect($template->render($env->newRenderContext(data: $data)))->toBe('1|100');
    expect($store->rows)->toBe(2);
    expect($store->queries)->toBe(3);
    expect(fn () => testParseString($env, '{{ collection.products[1000].id }}')->render($env->newRenderContext(data: $data)))
        ->toThrow(Keepsuit\Liquid\Exceptions\UndefinedVariableException::class);
})->with('template backends');
