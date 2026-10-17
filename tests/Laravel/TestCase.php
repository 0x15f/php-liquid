<?php

namespace Keepsuit\Liquid\Tests\Laravel;

use Keepsuit\Liquid\Laravel\LiquidServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected string $viewsPath;

    protected function getPackageProviders($app): array
    {
        return [LiquidServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->viewsPath = testTemporaryDirectory()->path();
        file_put_contents($this->viewsPath.'/hello.liquid', 'Hello {{ name }}!{% render "footer", label: name %}');
        file_put_contents($this->viewsPath.'/footer.liquid', '<small>{{ label | escape }}</small>');
        $app['config']->set('view.paths', [$this->viewsPath]);
        $app['config']->set('liquid.cache.driver', 'memory');
        $app['config']->set('liquid.cache.path', testTemporaryDirectory()->path('compiled'));
        $app['config']->set('liquid.cache.prefix', 'test-'.bin2hex(random_bytes(8)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.cache', [
            'host' => getenv('LIQUID_REDIS_HOST') ?: '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);
        $app['config']->set('cache.stores.redis', ['driver' => 'redis', 'connection' => 'cache']);
    }
}
