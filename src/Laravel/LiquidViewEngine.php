<?php

namespace Keepsuit\Liquid\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Engine;

final class LiquidViewEngine implements Engine
{
    public function __construct(private readonly Application $app) {}

    public function get($path, array $data = []): string
    {
        // Laravel shares its container and execution internals with PHP views.
        // Do not make those implicit objects available to Liquid templates.
        unset($data['app'], $data['__env'], $data['__data'], $data['__path']);

        return $this->app->make(LiquidManager::class)->renderPath($path, $data);
    }
}
