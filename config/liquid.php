<?php

return [
    'namespace' => env('LIQUID_NAMESPACE', 'default'),
    'strict_variables' => false,
    'strict_filters' => true,
    'rethrow_errors' => true,
    'cache' => [
        'driver' => env('LIQUID_CACHE_DRIVER', env('APP_DEBUG', false) ? 'memory' : 'compiled'),
        // Optional shared L2 for compiled mode, required for the cache-only mode.
        'store' => env('LIQUID_CACHE_STORE'),
        'path' => storage_path('framework/cache/liquid'),
        'prefix' => env('LIQUID_CACHE_PREFIX', 'liquid'),
        // Set to an immutable release/theme revision; change when templates or extension code changes.
        'version' => env('LIQUID_CACHE_VERSION', 'v1'),
        'ttl' => (int) env('LIQUID_CACHE_TTL', 3600),
    ],
    'limits' => [
        'render_length' => 10_000_000,
        'render_score' => 100_000,
        'assign_score' => 100_000,
        'cumulative_render_score' => 1_000_000,
        'cumulative_assign_score' => 1_000_000,
    ],
    'extensions' => [],
    'tags' => [],
    'filters' => [],
];
