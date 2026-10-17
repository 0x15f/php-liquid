# Security

For vulnerabilities, use this repository's **Security → Report a vulnerability** flow when available. If private reporting is unavailable, contact the maintainer through their GitHub profile before posting exploit details publicly. Do not include credentials, customer data or private templates in public issues.

## Trust boundaries

The general `EnvironmentFactory::new()` and Laravel view APIs are compatibility APIs for application-managed templates. They do not enable the restricted policy below automatically.

## User-authored templates

Use an application-authorized snapshot containing only the templates and snippets the current tenant may access. Neither file names nor cache namespaces establish authorization. The restricted factory accepts `ArrayFileSystem`, `TemplateSnapshot` or a blank filesystem and never consults Laravel's global view finder. Restricted cache keys include the snapshot and policy, and authorization is checked before cache lookup.

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

For large catalogs of templates, construct a `TemplateSnapshot` from authorized names and their SHA-256 source hashes, computed when publishing a revision. Its application-owned loader has signature `Closure(string $name, int $maxBytes): string`. It is called only for authorized names on cache misses, and the fetched source must match the manifest hash. Implement bounded reads and timeouts inside the loader. A warm compiled or Redis cache hit fetches no template source:

```php
use Keepsuit\Liquid\FileSystems\TemplateSnapshot;

$snapshot = new TemplateSnapshot($authorizedSourceHashes, $loadAuthorizedSource);
$environment = Liquid::untrustedEnvironment($snapshot, "tenant:{$tenantId}");
```

The manifest contains at most 1,000 names; only requested root/snippet sources are loaded. The aggregate parse budget applies to newly fetched sources. Array snapshots additionally validate their complete source-byte budget before environment creation.

In Laravel, the manager builds the cache identity from the tenant label, the entire authorized snapshot, the capability policy and the configured package/cache revision. It uses the same memory, compiled and shared-cache drivers as normal templates, including Redis L2:

```php
use Keepsuit\Liquid\Laravel\Facades\Liquid;

$environment = Liquid::untrustedEnvironment($authorizedTemplates, "tenant:{$tenantId}");
$html = $environment->parseTemplate('page')
    ->render($environment->newRenderContext(data: $authorizedData));
```

Restricted environments start with standard tags and filters. Laravel's application-wide custom extensions, tags and filters are not inherited. Explicitly registering PHP extensions grants trusted application capabilities; review them before enabling them here.

The registered tag/filter classes and extension classes are part of restricted cache identity. Capabilities become immutable when parsing starts; configure them beforehand and create a fresh environment to change them. Application-wide custom condition-operator callbacks are ignored in restricted environments. Opaque PHP extension state still belongs to the application: partition standalone cache directories and application revisions by tenant and extension configuration.

The default policy rejects PHP objects, closures and resources in input arrays, filter inputs/results and object lookups. Internal Liquid loop/range objects are permitted. Applications can explicitly allow exact class names through `TemplatePolicy(allowedClasses: [...])`. This approves the class's PHP behavior, including getters, conversions and every public Drop method normally exposed by Liquid. Use small, read-only adapters; never approve a container, Eloquent model, query builder or general-purpose application object. Subclasses are not implicitly approved. Returned objects must also be approved.

Default core restricted budgets (Laravel also applies any lower configured limits):

| Resource | Limit |
| --- | --- |
| One template | 256 KiB |
| Array snapshot / newly parsed transitive source | 1 MiB |
| Snapshot files / name bytes | 1,000 / 256 |
| Tokens across a root parse and newly parsed snippets | 20,000 |
| Input structural depth / values / string-and-key bytes | 32 / 100,000 / 10,000,000 |
| One materialized collection | 10,000 items |
| One intermediate buffer / total emitted output | 10,000,000 bytes |
| Render work / cumulative work | 100,000 / 1,000,000 |
| Assigned bytes/score / cumulative assignments | 10,000,000 / 20,000,000 |
| Cooperative render deadline | 1,000 ms |

Growing strings, captures, native loop ranges and collection materialization are checked before growth. Filters with a fixed expansion ratio may conservatively reject an operation whose worst-case size exceeds the budget. Range `first`, `last`, `size` and bounded `slice` avoid full materialization. Decimal arithmetic operands are limited to 256 bytes in restricted mode. Applications own the policy and any explicit overrides; template input must never configure these limits.

Compilation follows the environment's profile. Restricted environments and environments configured with bounded `ResourceLimits` before parsing emit allocation guards. Unrestricted compiled artifacts reject restricted or bounded contexts before execution; configure the environment before parsing instead of upgrading an unrestricted artifact's context later. Guarded artifacts support different application-owned ceilings in fresh request contexts. Cache identities separate the profiles. Legacy compiled artifacts without guard metadata also reject bounded contexts; rebuild them when upgrading.

Approved `ProvidesCollectionSlice` adapters receive a finite probe of at most collection-limit + 1 rows when no smaller positive limit is supplied. An oversized result is rejected. Negative provider limits are rejected in bounded mode. Adapters must enforce row/byte budgets while fetching, authorize every query, and configure database statement timeouts. This does not constrain rows examined by the database or preempt a blocking PHP callback.

Object normalization and property lookups consume work and check cooperative deadlines between PHP data accesses. Provider budget exceptions propagate through magic-property probes instead of being treated as missing fields. An approved callback still executes trusted PHP; its own internal loops, database statements and allocations need application limits.

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

This requires POSIX PHP CLI and `proc_open`; FPM callers need a CLI binary installed (`phpBinary` can select it). Composer autoload discovery is automatic; `autoloadPath` can select an application-controlled autoloader. The child does not bootstrap Laravel. Composer autoload files, PHP extensions and the PHP binary remain trusted code. Only UTF-8 JSON values cross the process boundary: PHP objects, custom Drops, callbacks and database adapters are unavailable in this mode. Requests are capped at 4 MiB, with a conservative preflight budget for JSON escaping before parent-side encoding. This can reject a request whose actual encoded size would be smaller. The caller timezone is forwarded for date filters. Errors do not expose child diagnostics.

The child uses compiled templates in a private cache, reuses the current snapshot's environment with fresh contexts, retains at most four compiled snapshot directories, and recycles after the configured number of requests. CLI OPcache is disabled in this worker so its shared-memory allocation does not sit outside the PHP heap allowance. Temporary compiled artifacts are removed on recycling/close. Parent deadlines terminate and discard the worker; the next request can start a fresh one. A PHP heap limit does not measure all operating-system RSS; deployments can additionally enforce container/process memory and CPU limits. This is a PHP rendering boundary, not an OS filesystem/network namespace.

`$renderer->stream(...)` uses the same inputs, caching, limits and worker lifecycle. It transfers chunks incrementally and preserves binary output bytes. Dropping an active stream discards its worker and unread frames. Only one request may be active on each renderer instance. The parent deadline includes time the consumer spends paused; configure it for the response lifecycle. Runtime failures can follow already-emitted chunks and cannot retract them.

Use the in-process restricted API with an externally limited application worker when you need approved lazy database adapters and shared Redis template caching. Use the minimal isolated renderer when you need independent request termination and value-only inputs. Neither mode provides tenant authorization for your application.

## Infrastructure and output

Liquid templates do not directly execute arbitrary PHP expressions. PHP extensions, custom tags, filters, Drops, cache payloads and compiled artifacts are application code/infrastructure and must be trusted. Expose narrow Drops or prepared arrays, not a service container or arbitrary application objects. The Laravel view engine excludes framework execution objects that Laravel implicitly shares with PHP views.

HTML output is not automatically escaped. Use `escape` where untrusted values enter HTML; apply context-appropriate handling for URLs, JavaScript and other contexts.

Store compiled PHP outside web-accessible or untrusted-writable directories. Use application-controlled Redis/cache credentials and network access. Shared template serialization is not an untrusted message format. The shared cache never evaluates cached PHP source, but a compromised cache is outside the supported trust boundary.

Request contexts and personalized objects must be fresh. Cache namespaces isolate template versions; they are not authorization. Applications must select authorized tenants, filesystem roots and data, and publish consistent template/snippet revisions.

Output/work/resource budgets are useful limits, not a complete memory, wall-clock or database sandbox. Some operations materialize collections. Provider row/byte budgets count values after database reads, not database rows examined or statement duration. Use application/database limits and isolate workloads as appropriate.

Generated PHP classes stay loaded until the worker exits. Use bounded worker lifetimes and validate deployment/revision churn. Validate real templates and production cache configuration before rollout.
