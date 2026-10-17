<?php

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\Laravel\LaravelCompiledTemplatesCache;
use Keepsuit\Liquid\Laravel\LaravelTemplatesCache;

uses(Keepsuit\Liquid\Tests\Laravel\TestCase::class);

test('shared parsed cache roundtrips expires and removes templates', function () {
    $store = new Repository(new ArrayStore);
    $cache = new LaravelTemplatesCache($store, 'one', 1);
    $template = EnvironmentFactory::new()->build()->parseString('Hello {{ name }}');
    $cache->set('greeting', $template);
    $loaded = $cache->get('greeting');
    expect($loaded)->not->toBe($template);
    $env = EnvironmentFactory::new()->build();
    expect($loaded->render($env->newRenderContext(data: ['name' => 'A'])))->toBe('Hello A');
    $this->travel(2)->seconds();
    expect($cache->get('greeting'))->toBeNull();
    $this->travelBack();
    $cache->set('greeting', $template);
    expect($cache->has('greeting'))->toBeTrue();
    $cache->remove('greeting');
    expect($cache->has('greeting'))->toBeFalse();
});

test('shared clear rotates only its namespace and preserves in-flight snapshots', function () {
    $store = new Repository(new ArrayStore);
    $store->forever('other-feature', 'keep');
    $one = new LaravelTemplatesCache($store, 'one');
    $two = new LaravelTemplatesCache($store, 'two');
    $template = EnvironmentFactory::new()->build()->parseString('ok');
    $one->set('a', $template);
    $two->set('a', $template);
    $inflight = new LaravelTemplatesCache($store, 'one');
    expect($inflight->has('a'))->toBeTrue();
    $one->clear();
    expect((new LaravelTemplatesCache($store, 'one'))->get('a'))->toBeNull();
    expect($inflight->has('a'))->toBeTrue();
    expect($two->has('a'))->toBeTrue();
    expect($store->get('other-feature'))->toBe('keep');
});

test('another host compiles a shared parsed template without rereading its source', function () {
    $store = new Repository(new ArrayStore);
    $env = EnvironmentFactory::new()->build();
    $template = $env->parseString('Hello {{ name }}');
    $hostA = new LaravelCompiledTemplatesCache(new LaravelTemplatesCache($store, 'same'), testTemporaryDirectory()->path('a'));
    $hostB = new LaravelCompiledTemplatesCache(new LaravelTemplatesCache($store, 'same'), testTemporaryDirectory()->path('b'));
    $hostA->set('greeting', $template);
    $loaded = $hostB->get('greeting');
    expect($loaded)->toBeInstanceOf(Keepsuit\Liquid\Compiler\CompiledTemplate::class);
    expect($loaded->render($env->newRenderContext(data: ['name' => 'B'])))->toBe('Hello B');
    $hostA->clear();
    $newRequest = new LaravelCompiledTemplatesCache(new LaravelTemplatesCache($store, 'same'), testTemporaryDirectory()->path('c'));
    expect($newRequest->get('greeting'))->toBeNull();
});

test('Redis parsed and compiled caches invalidate across fresh request scopes', function () {
    if (! getenv('LIQUID_REDIS_HOST')) {
        $this->markTestSkipped('Set LIQUID_REDIS_HOST to run the real Redis integration test.');
    }
    $store = cache()->store('redis');
    $scope = 'redis-test-'.bin2hex(random_bytes(8));
    $sentinel = $scope.':unrelated';
    $store->put($sentinel, 'keep', 60);
    $env = EnvironmentFactory::new()->build();
    $a = new LaravelCompiledTemplatesCache(new LaravelTemplatesCache($store, $scope, 1), testTemporaryDirectory()->path('a'));
    $a->set('hello', $env->parseString('Hi {{ name }}'));
    $b = new LaravelCompiledTemplatesCache(new LaravelTemplatesCache($store, $scope, 1), testTemporaryDirectory()->path('b'));
    expect($b->get('hello')->render($env->newRenderContext(data: ['name' => 'B'])))->toBe('Hi B');
    $a->clear();
    $c = new LaravelCompiledTemplatesCache(new LaravelTemplatesCache($store, $scope, 1), testTemporaryDirectory()->path('c'));
    expect($c->get('hello'))->toBeNull();
    expect($store->get($sentinel))->toBe('keep');
    $parsed = new LaravelTemplatesCache($store, $scope, 1);
    $parsed->set('expires', $env->parseString('x'));
    usleep(1_200_000);
    expect($parsed->get('expires'))->toBeNull();
    $store->forget($sentinel);
    $parsed->clear();
});
