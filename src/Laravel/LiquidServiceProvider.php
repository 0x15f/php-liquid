<?php

namespace Keepsuit\Liquid\Laravel;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Factory;
use Keepsuit\Liquid\Environment;
use Keepsuit\Liquid\Laravel\Commands\CacheTemplatesCommand;
use Keepsuit\Liquid\Laravel\Commands\ClearTemplatesCommand;

/** @phpstan-import-type Config from LiquidManager */
final class LiquidServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(dirname(__DIR__, 2).'/config/liquid.php', 'liquid');
        $this->app->scoped(LaravelFileSystem::class, function (Application $app): LaravelFileSystem {
            /** @var Factory $views */
            $views = $app->make('view');

            return new LaravelFileSystem($views->getFinder());
        });
        $this->app->scoped(LiquidManager::class, function (Application $app): LiquidManager {
            /** @var Repository $repository */
            $repository = $app->make('config');
            /** @var Config $config */
            $config = $repository->get('liquid');

            return new LiquidManager($app->make(LaravelFileSystem::class), $app->make(CacheFactory::class), $config);
        });
        $this->app->scoped(Environment::class, fn (Application $app) => $app->make(LiquidManager::class)->environment());
    }

    public function boot(): void
    {
        $this->publishes([dirname(__DIR__, 2).'/config/liquid.php' => $this->app->configPath('liquid.php')], 'liquid-config');
        $this->callAfterResolving('view', function (Factory $views): void {
            // Resolve the scoped manager when rendering, not when the singleton
            // view factory/engine resolver is first created in an Octane worker.
            $views->addExtension('liquid', 'liquid', fn () => new LiquidViewEngine($this->app));
        });
        if ($this->app->runningInConsole()) {
            $this->commands([ClearTemplatesCommand::class, CacheTemplatesCommand::class]);
        }
    }
}
