<?php

use Keepsuit\Liquid\Compiler\CompiledTemplate;
use Keepsuit\Liquid\Environment;
use Keepsuit\Liquid\Laravel\Facades\Liquid;
use Keepsuit\Liquid\Laravel\LiquidManager;

uses(Keepsuit\Liquid\Tests\Laravel\TestCase::class);

test('Laravel views facade dependency injection and nested snippets render', function () {
    expect(view('hello', ['name' => 'Alice & Bob'])->render())->toBe('Hello Alice & Bob!<small>Alice &amp; Bob</small>');
    expect(Liquid::render('hello', ['name' => 'Jane']))->toBe('Hello Jane!<small>Jane</small>');
    expect(Liquid::renderString('Hi {{ name | upcase }}', ['name' => 'sam']))->toBe('Hi SAM');
    expect($this->app->make(Environment::class))->toBe($this->app->make(LiquidManager::class)->environment());
});

test('namespaced Laravel views resolve Liquid snippets through the view finder', function () {
    view()->addNamespace('shop', $this->viewsPath);
    expect(view('shop::hello', ['name' => 'Sam'])->render())->toBe('Hello Sam!<small>Sam</small>');
});

test('view integration excludes framework execution objects from Liquid', function () {
    file_put_contents($this->viewsPath.'/safe.liquid', '{{ app | default: "absent" }}|{{ __env | default: "absent" }}');
    expect(view('safe')->render())->toBe('absent|absent');
});

test('a persistent view engine and facade resolve a fresh scoped manager', function () {
    $first = $this->app->make(LiquidManager::class);
    expect(view('hello', ['name' => 'Alice'])->render())->toContain('Alice');
    $this->app->forgetScopedInstances();
    $next = $this->app->make(LiquidManager::class);
    expect($next)->not->toBe($first);
    expect(Liquid::getFacadeRoot())->toBe($next);
    expect(view('hello', ['name' => 'Bob'])->render())->toBe('Hello Bob!<small>Bob</small>');
    $this->app->forgetScopedInstances();
    expect(view('hello', ['name' => 'Alice'])->render())->toBe('Hello Alice!<small>Alice</small>');
});

test('compiled views and namespaces remain isolated', function () {
    config()->set('liquid.cache.driver', 'compiled');
    $manager = $this->app->make(LiquidManager::class);
    expect($manager->render('hello', ['name' => 'A'], 'tenant-a:revision-1'))->toContain('Hello A!');
    expect($manager->environment('tenant-a:revision-1')->parseTemplate('hello'))->toBeInstanceOf(CompiledTemplate::class);
    expect($manager->environment('tenant-a:revision-1'))->not->toBe($manager->environment('tenant-b:revision-1'));
    file_put_contents($this->viewsPath.'/hello.liquid', 'Updated {{ name }}');
    expect($manager->render('hello', ['name' => 'B'], 'tenant-b:revision-1'))->toBe('Updated B');
    expect($manager->render('hello', ['name' => 'A'], 'tenant-a:revision-1'))->toContain('Hello A!');
});

test('clear command invalidates the chosen namespace without flushing application cache', function () {
    config()->set('liquid.cache.driver', 'compiled');
    config()->set('liquid.cache.store', 'array');
    cache()->forever('unrelated', 'keep');
    $manager = $this->app->make(LiquidManager::class);
    $manager->render('hello', ['name' => 'Alice'], 'shop');
    file_put_contents($this->viewsPath.'/hello.liquid', 'Updated {{ name }}');
    $this->artisan('liquid:clear', ['--namespace' => 'shop'])->assertExitCode(0);
    expect($manager->render('hello', ['name' => 'Bob'], 'shop'))->toBe('Updated Bob');
    expect(cache()->get('unrelated'))->toBe('keep');
});

test('configuration can be published and contains no closures', function () {
    $this->artisan('vendor:publish', ['--tag' => 'liquid-config', '--force' => true])->assertExitCode(0);
    expect(is_file(config_path('liquid.php')))->toBeTrue();
    expect(unserialize(serialize(config('liquid'))))->toBe(config('liquid'));
});

test('invalid cache configuration fails explicitly', function () {
    config()->set('liquid.cache.driver', 'cache');
    expect(fn () => Liquid::render('hello', ['name' => 'A']))->toThrow(InvalidArgumentException::class, 'liquid.cache.store');
});

test('warm command compiles roots and static snippets without requiring render data', function () {
    config()->set('liquid.cache.driver', 'compiled');
    $this->artisan('liquid:cache', ['views' => ['hello'], '--namespace' => 'release'])->assertExitCode(0);
    $env = $this->app->make(LiquidManager::class)->environment('release');
    expect($env->templatesCache->has('hello'))->toBeTrue();
    expect($env->templatesCache->has('footer'))->toBeTrue();
});
