# Laravel integration

Laravel 12 and 13 are supported. Standalone use of the core engine does not require Laravel. Composer's package discovery registers `Keepsuit\Liquid\Laravel\LiquidServiceProvider` in Laravel applications. Configurations containing class names and scalars work with `php artisan config:cache`; config files contain no closures.

## Views and direct rendering

Files under Laravel view paths use the `.liquid` extension. `view('shop.index', $data)` resolves `resources/views/shop/index.liquid`. Namespaced package views are supported. Snippets use Laravel view names, for example `{% render 'snippets.card', product: product %}`.

```php
use Keepsuit\Liquid\Environment;
use Keepsuit\Liquid\Laravel\Facades\Liquid;
use Keepsuit\Liquid\Laravel\LiquidManager;

$html = Liquid::render('shop.index', ['products' => $products]);
$html = Liquid::renderString('Hello {{ name | escape }}', ['name' => 'Alex']);
$environment = app(Environment::class);
$renderer = app(LiquidManager::class);
```

Data is not automatically HTML-escaped: use Liquid's `escape` filter. The view engine removes Laravel's implicit `app`, `__env`, `__data` and `__path` values. Expose intentional application data through arrays or narrow Drops rather than sharing service containers or unrestricted model objects.

`LiquidManager`, its environments and its filesystem are scoped to the Laravel request/job lifecycle. The persistent view engine resolves that scoped manager at render time, and the facade does not retain an old manager. Applications that manage their own worker lifecycle must flush Laravel scoped instances between jobs. Each render creates a fresh context; do not put personalized Drops or query sessions in a singleton, configuration file or compiled template.

## Cache modes

User-authored templates use `Liquid::untrustedEnvironment($authorizedTemplatesOrManifest, $tenantRevision)`. It retains the configured cache driver, limits PHP capabilities, partitions entries by authorized source snapshot and policy, and avoids the global view finder. See [restricted execution](SECURITY.md#user-authored-templates). Application-wide custom PHP tags, filters and extensions are not inherited by restricted environments.

Restricted environments compile with allocation guards. Their artifacts accept fresh per-request limits; unrestricted view artifacts reject bounded contexts. Compilation profiles have separate cache identities. This release changes the cache format; restart long-lived workers and rebuild standalone compiled caches when upgrading.

Publish `config/liquid.php` with `php artisan vendor:publish --tag=liquid-config`.

| Driver | Behavior |
|---|---|
| `memory` | Request-scoped parsed templates; default when `APP_DEBUG=true`, allowing edits between requests. |
| `compiled` | Local PHP artifacts with OPcache. Optional `cache.store` adds a shared parsed-template layer. |
| `cache` | Parsed templates in the named Laravel store. `cache.store` is required. |

For Redis-backed compiled rendering:

```dotenv
LIQUID_CACHE_DRIVER=compiled
LIQUID_CACHE_STORE=redis
LIQUID_CACHE_PREFIX=storefront
LIQUID_CACHE_VERSION=release-2026-10
LIQUID_CACHE_TTL=3600
```

Configure the Redis connection/client in Laravel's normal database/cache configuration. Any compatible Laravel repository can be used, including file, database, array and Memcached stores. Only Redis and array stores are exercised in the integration tests; other stores follow the same cache contract. This package never calls the application's global cache flush.

A cold server first checks Redis for a parsed template, then compiles a local PHP artifact. Warm compiled hits use the local artifact. Within a request, compiled template objects are reused; each render still creates a fresh data context. Each namespace reads its generation marker once per scoped cache instance, rather than adding a Redis round trip to every snippet. `cache.ttl` expires shared parsed entries; it does not expire immutable local compiled files. The compiled cache is application-controlled executable storage and must be outside public/web-writable directories.

Cache payloads and custom AST objects use PHP serialization, as Laravel stores commonly do. Use a trusted private application cache. Redis values are not evaluated as PHP source. Custom tags/AST objects must be serializable for shared caching; the plain compiled or memory driver is available when a shared backend is unsuitable.

## Streaming

Streaming is stable for parsed and compiled templates, including restricted environments and Redis-backed caches. `Liquid::stream($name, $data, $namespace)` and `Liquid::streamString($source, $data, $namespace)` return `Generator<int, string>`. For an authorized user template, obtain its restricted environment and call the template's `stream()` method:

```php
use Keepsuit\Liquid\Laravel\Facades\Liquid;

$environment = Liquid::untrustedEnvironment($authorizedSnapshot, "tenant:{$tenantId}");
$template = $environment->parseTemplate('page'); // Resolve and validate before sending headers.

return response()->stream(function () use ($template, $environment, $authorizedData): void {
    $stream = $template->stream($environment->newRenderContext(data: $authorizedData));
    try {
        foreach ($stream as $chunk) {
            echo $chunk;
            if (connection_aborted()) {
                break;
            }
        }
    } finally {
        unset($stream);
    }
}, 200, ['Content-Type' => 'text/html; charset=UTF-8', 'X-Accel-Buffering' => 'no']);
```

The contract is byte equality with `render()` for the same repeatable input and fresh contexts. Keys are sequential integers; chunk sizes and boundaries are not stable. Output is pull-based with a small internal buffer, but captures, filters and individual nodes can materialize bounded intermediate values. Streaming does not turn arbitrary database operations into cursors or batch queries.

The root checks total emitted bytes before yielding each chunk. Work and allocation limits also apply inside snippets and captures. A runtime failure can occur after a prefix has reached the client; handle that as a partial response, since headers and bytes cannot be retracted. Syntax/static-snippet validation before sending headers reduces this risk but cannot eliminate runtime failures.

Use one context per response. Overlapping root renders/streams on one context are rejected. On cancellation, release **all** references to the generator so PHP runs `finally` blocks and restores loop scopes. A reusable `IsolatedRenderer` also offers `stream()`; cancellation discards its child process and unread frames. Its deadline includes consumer pauses, and it permits one active request per renderer instance.

Web-server, proxy and compression buffering can delay delivery independently of Liquid. Configure those layers and measure first-byte latency through the real HTTP stack. Keep application worker lifetimes bounded as described below.

## Invalidation and deployment

Treat `cache.version` as an immutable template/application release revision. Change it when root templates, snippets, filters, tags or extension code changes. Cache identity also includes the installed package reference, PHP major/minor, class configuration, prefix and caller namespace. An immutable revision lets old requests finish against their prior templates while new requests use the new revision.

```bash
php artisan liquid:cache home products.index --namespace=tenant-17:theme-42
php artisan liquid:clear --namespace=tenant-17:theme-42
```

Warming parses/compiles root views and static snippets without rendering request data. Supply dynamic snippet names explicitly. With the memory driver a warm command has no effect after that command exits; use a persistent driver for deployment warming.

With a shared store, clearing rotates only that Liquid namespace's generation. New request/cache instances see the new generation on every server, while in-flight instances may finish using their previous snapshot. Removing one compiled template conservatively invalidates its entire namespace so another server cannot serve an older compiled copy. Old generation files are retained to avoid disrupting in-flight renders; prune obsolete version/generation directories only after old workers/requests have drained. Old shared entries expire by TTL. Without a shared store, clearing compiled artifacts affects only that host; deploy a new revision or clear each host.

`clear` is an invalidation operation, not a cluster-wide filesystem cleanup. Root templates and snippets should be published as a consistent revision. Warm before switching traffic, use bounded worker lifetimes for generated PHP classes, and measure cold/revision churn separately from warm rendering.

## Tenant namespaces and extensions

```php
$html = Liquid::render('shop.index', $data, namespace: 'tenant-17:theme-42');
$environment = Liquid::environment('tenant-17:theme-42');
```

Namespaces partition caches; they do not implement tenant authorization or choose a template filesystem. Your application must select authorized data and view roots. Never derive a trusted tenant identity solely from unchecked request input. Use different namespaces for different template sets/revisions even if view names are identical.

Configure no-argument extension/tag/filter provider classes in `liquid.extensions`, `liquid.tags` and `liquid.filters`. More dynamic setup can customize the scoped environment after resolution. Change the cache revision when custom behavior changes. The core standalone factory API remains available.

## Data loading

`Analysis\DependencyPlanner` produces immutable, cacheable field hints. `Contracts\ProvidesCollectionSlice` allows bounded SQL-backed iteration before generic materialization. The example product/variant repository under `performance/Loading` is a development fixture, not an Eloquent production adapter. Keep authorization, query batching, catalog snapshot consistency and request data caches in the application. [Loading documentation](performance/Loading/README.md) explains the contract and its limits.
