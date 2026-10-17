<?php

namespace Keepsuit\Liquid\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Keepsuit\Liquid\Laravel\LiquidManager;

/** @mixin LiquidManager */
final class Liquid extends Facade
{
    protected static $cached = false;

    protected static function getFacadeAccessor(): string
    {
        return LiquidManager::class;
    }
}
