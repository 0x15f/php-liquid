<?php

use Keepsuit\Liquid\Analysis\DependencyPlanner;
use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Tags\Custom\DynamicRenderTag;

function loadingPlan(string $root, array $partials = []): Keepsuit\Liquid\Analysis\DependencyPlan
{
    $environment = EnvironmentFactory::new()->setFilesystem(new Keepsuit\Liquid\Tests\Stubs\StubFileSystem($partials))->build();

    return (new DependencyPlanner(fn (string $name) => $environment->parseString($partials[$name], $name)))
        ->analyse($environment->parseString($root));
}

test('planner follows aliases and nested snippets without executing them', function () {
    $plan = loadingPlan('{% assign selected = collection.products %}{% for p in selected reversed limit: 24 offset: 3 %}{% render "card", item: p %}{% endfor %}', [
        'card' => '{{ item.title | escape }}{% render "price" with item as money %}{% for v in item.variants %}{{ v.title }}{% endfor %}',
        'price' => '{{ money.price_formatted }}',
    ]);
    expect($plan->fields('collection.products.*'))->toContain('title', 'price_formatted', 'variants');
    expect($plan->fields('collection.products.*.variants.*'))->toBe(['title']);
    expect($plan->templates)->toBe(['card', 'price']);
    expect($plan->loops[0])->toBe(['path' => 'collection.products', 'limit' => 24, 'offset' => 3, 'reversed' => true, 'static_window' => true]);
    expect(json_decode(json_encode($plan), true)['version'])->toBe(1);
});

test('planner marks dynamic lookups and preserves their input dependencies', function () {
    $plan = loadingPlan('{% for p in collection.products limit: page_size %}{{ p[field] }}{{ p.metafields[namespace][key] }}{% endfor %}');
    expect($plan->paths)->toContain('collection.products.*.*', 'field', 'namespace', 'key', 'page_size');
    expect($plan->runtimeReasons)->toContain('dynamic bracket lookup');
    expect($plan->loops[0]['static_window'])->toBeFalse();
});

test('planner does not claim aliases survive conditional assignments or filters', function () {
    $plan = loadingPlan('{% assign item = product %}{% if customer.a %}{% assign item = other %}{% endif %}{{ item.title }}{% assign items = collection.products | sort: "title" %}{% for x in items limit: 2 %}{{ x.title }}{% endfor %}');
    expect($plan->paths)->not->toContain('product.title', 'other.title', 'collection.products.*.title');
    expect($plan->loops[0]['static_window'])->toBeFalse();
});

test('planner limits recursive snippets and reuses no previous analysis state', function () {
    $env = EnvironmentFactory::new()->setFilesystem(new Keepsuit\Liquid\Tests\Stubs\StubFileSystem(['loop' => '']))->build();
    $partial = $env->parseString('{% render "loop" %}{{ product.title }}');
    $planner = new DependencyPlanner(fn (string $name) => $partial);
    $first = $planner->analyse($partial);
    $second = $planner->analyse($env->parseString('{{ customer.id }}'));
    expect($first->runtimeReasons)->toContain('recursive snippet');
    expect($second->paths)->toBe(['customer.id']);
    expect($second->templates)->toBe([]);
});

test('planner reports dynamic snippets without trying to load a guessed name', function () {
    $env = EnvironmentFactory::new()->registerTag(DynamicRenderTag::class)->build();
    $planner = new DependencyPlanner(function (string $name): never {
        throw new RuntimeException('Must not guess snippet');
    });
    $plan = $planner->analyse($env->parseString('{% render snippet, product: product %}'));
    expect($plan->runtimeReasons)->toContain('dynamic snippet name');
    expect($plan->paths)->toContain('snippet', 'product');
});

test('explicit snippet arguments override its implicit variable alias', function () {
    $plan = loadingPlan('{% for p in collection.products limit: 2 %}{% render "card", card: p %}{% endfor %}', ['card' => '{{ card.title }}']);
    expect($plan->fields('collection.products.*'))->toBe(['title']);
});
