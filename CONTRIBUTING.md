# Contributing

Use PHP 8.2+ with mbstring and Composer, or the isolated Docker development image below. The package keeps its core framework-independent; Laravel integrations are tested with Orchestra Testbench. Preserve upstream attribution and add regression coverage for Liquid semantics or cache lifecycle changes.

```bash
docker build -f tools/Dockerfile -t php-liquid-dev .
docker network create php-liquid-tests
docker run -d --name php-liquid-redis --network php-liquid-tests redis:7.4-alpine
docker run --rm --network php-liquid-tests \
  -e LIQUID_REDIS_HOST=php-liquid-redis \
  -v "$PWD:/package" -w /package php-liquid-dev \
  sh -c 'composer install && vendor/bin/pest && vendor/bin/phpstan analyse && vendor/bin/pint --test'
```

The development container includes PhpRedis. For standalone installations, Redis is optional. Without `LIQUID_REDIS_HOST`, the real Redis integration test is skipped; the other cache tests still run with Laravel's array store. CI runs Redis integration on Laravel 12 and 13. Keep Redis integration tests isolated; never point them at an application Redis instance.

For a framework-specific dependency set, use `composer require --dev 'orchestra/testbench:^10.0' -W` for Laravel 12 or `^11.0` for Laravel 13 inside the container. Do not commit `vendor`, root `composer.lock`, generated templates, local configuration or benchmark databases.

Pure rendering tests cover parsed and compiled backends; use `vendor/bin/pest tests/Laravel` for the integration suite. `vendor/bin/phpstan analyse` checks library source and existing performance adapters. The separate Rust/WASM benchmark uses runtime-generated classes/FFI and has its documented analysis exclusion.

See [performance/README.md](performance/README.md) for benchmarks. Do not compare timings collected under coverage/debugging extensions, publish batch averages as request p99, or infer production capacity from local fixtures. Changes to benchmark fixtures require independent Shopify reference regeneration. Preserve old raw measurements as historical snapshots rather than silently rewriting their numbers or fingerprints.

Cache/compiler changes should test revision isolation, cross-request state, partial rendering and invalidation. Generated PHP belongs on trusted local disk; shared caches are trusted application infrastructure. Prefer immutable deployment revisions and keep cache clearing scoped to this package's namespace.

Please report security issues using [SECURITY.md](SECURITY.md). For normal bugs include PHP/framework versions, a minimal Liquid template, input data, expected/actual output and the cache backend. Use synthetic data in public reports.
