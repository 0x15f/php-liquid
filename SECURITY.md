# Security

For vulnerabilities, use this repository's **Security → Report a vulnerability** flow when available. If private reporting is unavailable, contact the maintainer through their GitHub profile before posting exploit details publicly. Do not include credentials, customer data or private templates in public issues.

## Trust boundaries

The general `EnvironmentFactory::new()` and Laravel view APIs are compatibility APIs for application-managed templates. They do not enable the restricted policy below automatically.

## User-authored templates

Use an application-authorized snapshot containing only the templates and snippets the current tenant may access. Neither file names nor cache namespaces establish authorization. The restricted factory accepts `ArrayFileSystem` or a blank filesystem and never consults Laravel's global view finder.

```php
use Keepsuit\Liquid\EnvironmentFactory;
use Keepsuit\Liquid\FileSystems\ArrayFileSystem;
use Keepsuit\Liquid\TemplatesCache\CompiledTemplatesCache;

$environment = EnvironmentFactory::forUntrustedTemplates()
    ->setFilesystem(new ArrayFileSystem($authorizedTemplates))
    // Application-owned directory, scoped to tenant, immutable revision and policy.
    ->setTemplatesCache(new CompiledTemplatesCache($privateRevisionCachePath))
    ->build();

$html = $environment->parseTemplate('page')
    ->render($environment->newRenderContext(data: $authorizedData));
```

In Laravel, the manager builds the cache identity from the tenant label, the entire authorized snapshot, the capability policy and the configured package/cache revision. It uses the same memory, compiled and shared-cache drivers as normal templates, including Redis L2:

```php
use Keepsuit\Liquid\Laravel\Facades\Liquid;

$environment = Liquid::untrustedEnvironment($authorizedTemplates, "tenant:{$tenantId}");
$html = $environment->parseTemplate('page')
    ->render($environment->newRenderContext(data: $authorizedData));
```

Restricted environments start with standard tags and filters. Laravel's application-wide custom extensions, tags and filters are not inherited. Explicitly registering PHP extensions grants trusted application capabilities; review them before enabling them here.

The default policy rejects PHP objects, closures and resources in input arrays, filter inputs/results and object lookups. Internal Liquid loop/range objects are permitted. Applications can explicitly allow exact class names through `TemplatePolicy(allowedClasses: [...])`. This approves the class's PHP behavior, including getters, conversions and every public Drop method normally exposed by Liquid. Use small, read-only adapters; never approve a container, Eloquent model, query builder or general-purpose application object. Subclasses are not implicitly approved. Returned objects must also be approved.

Default restricted budgets:

| Resource | Limit |
| --- | --- |
| One template | 256 KiB |
| Authorized snapshot / transitive parse source | 1 MiB |
| Snapshot files / name bytes | 1,000 / 256 |
| Tokens across a root parse and newly parsed snippets | 20,000 |
| Input structural depth / values / string-and-key bytes | 32 / 100,000 / 10,000,000 |
| One materialized collection | 10,000 items |
| One intermediate buffer / total emitted output | 10,000,000 bytes |
| Render work / cumulative work | 100,000 / 1,000,000 |
| Assigned bytes/score / cumulative assignments | 10,000,000 / 20,000,000 |
| Cooperative render deadline | 1,000 ms |

Growing strings, captures, native loop ranges and collection materialization are checked before growth. Filters with a fixed expansion ratio may conservatively reject an operation whose worst-case size exceeds the budget. Range `first`, `last`, `size` and bounded `slice` avoid full materialization. Decimal arithmetic operands are limited to 256 bytes in restricted mode. Applications own the policy and any explicit overrides; template input must never configure these limits.

Approved `ProvidesCollectionSlice` adapters receive a finite probe of at most collection-limit + 1 rows when no smaller positive limit is supplied. An oversized result is rejected. Negative provider limits are rejected in bounded mode. Adapters must enforce row/byte budgets while fetching, authorize every query, and configure database statement timeouts. This does not constrain rows examined by the database or preempt a blocking PHP callback.

## Isolated execution

For hostile templates requiring an enforceable PHP heap limit and parent-enforced wall-clock deadline, use the reusable minimal worker:

```php
use Keepsuit\Liquid\Security\IsolatedRenderer;

$renderer = new IsolatedRenderer(
    timeoutMilliseconds: 1000,
    memoryMegabytes: 128,
    maxRequests: 100,
);
$html = $renderer->render($authorizedTemplates, 'page', $authorizedData, "tenant:{$tenantId}");
// Reuse the renderer, then explicitly close it when the application worker drains.
$renderer->close();
```

This requires POSIX PHP CLI and `proc_open`; FPM callers need a CLI binary installed (`phpBinary` can select it). Composer autoload discovery is automatic; `autoloadPath` can select an application-controlled autoloader. The child does not bootstrap Laravel. Composer autoload files, PHP extensions and the PHP binary remain trusted code. Only UTF-8 JSON-compatible data crosses the process boundary: custom Drops, callbacks and database adapters are unavailable in this mode. Requests are capped at 4 MiB. Errors do not expose child diagnostics.

The child uses compiled templates in a private cache, reuses the current snapshot's environment with fresh contexts, and recycles after the configured number of requests. CLI OPcache is disabled in this worker so its shared-memory allocation does not sit outside the PHP heap allowance. Temporary compiled artifacts are removed on recycling/close. Parent deadlines terminate and discard the worker; the next request can start a fresh one. A PHP heap limit does not measure all operating-system RSS; deployments can additionally enforce container/process memory and CPU limits. This is a PHP rendering boundary, not an OS filesystem/network namespace.

Use the in-process restricted API with an externally limited application worker when you need approved lazy database adapters and shared Redis template caching. Use the minimal isolated renderer when you need independent request termination and value-only inputs. Neither mode provides tenant authorization for your application.

## Infrastructure and output

Liquid templates do not directly execute arbitrary PHP expressions. PHP extensions, custom tags, filters, Drops, cache payloads and compiled artifacts are application code/infrastructure and must be trusted. Expose narrow Drops or prepared arrays, not a service container or arbitrary application objects. The Laravel view engine excludes framework execution objects that Laravel implicitly shares with PHP views.

HTML output is not automatically escaped. Use `escape` where untrusted values enter HTML; apply context-appropriate handling for URLs, JavaScript and other contexts.

Store compiled PHP outside web-accessible or untrusted-writable directories. Use application-controlled Redis/cache credentials and network access. Shared template serialization is not an untrusted message format. The shared cache never evaluates cached PHP source, but a compromised cache is outside the supported trust boundary.

Request contexts and personalized objects must be fresh. Cache namespaces isolate template versions; they are not authorization. Applications must select authorized tenants, filesystem roots and data, and publish consistent template/snippet revisions.

Output/work/resource budgets are useful limits, not a complete memory, wall-clock or database sandbox. Some operations materialize collections. Provider row/byte budgets count values after database reads, not database rows examined or statement duration. Use application/database limits and isolate workloads as appropriate.

Generated PHP classes stay loaded until the worker exits. Use bounded worker lifetimes and validate deployment/revision churn. Validate real templates and production cache configuration before rollout.
