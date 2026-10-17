# Planned, bounded Liquid data loading — prototype

This adds an advisory AST dependency planner and an opt-in collection slicing contract to the library. A **development-only SQLite catalog adapter** demonstrates projection, lazy batched relationships and request-scoped field caches. It does not connect to or modify PHP and Laravel. Results and limits are in [REPORT.md](REPORT.md).

## Request flow

1. At template publication/compilation, parse the template and scan its AST with `Analysis\DependencyPlanner`. Resolve static snippets through an explicit callback. Cache the immutable `DependencyPlan` alongside compiled templates.
2. Key that cache by **plan version, application schema/resolver version, template revision, and all transitive snippet revisions**. A theme-wide revision is a simple conservative option, especially with dynamic snippets. Never put request data or a live session in that cache.
3. For each request, create a new tenant-scoped repository and `CatalogSession`. Bind `ProductsCollection` under `collection.products`. The compiled template uses the normal Liquid syntax.
4. The renderer evaluates the real loop `limit` and `offset` and calls `ProvidesCollectionSlice::liquidSlice()`. The adapter queries only that window, selecting allowed fields identified by the plan. The static loop hints do **not** determine SQL bounds.
5. Access to `product.variants` batches the missing relation across products already selected in this session. If the branch does not execute, no variants query runs. Dynamic product/variant fields load once across the relevant selected IDs, including null-valued fields, and are cached for that request.

The planner walks the AST; it does not execute a discovery render, filters, Drops or database queries. It follows ordinary unfiltered assignment aliases, loop aliases, `render` arguments/`with`/`for`, nested static snippets and dynamic lookup input dependencies. It marks dynamic snippet names, filters and conditional/unsupported tags as requiring runtime evaluation. Recursive snippets and depth are bounded. Plans are advisory, serializable field hints, **not complete query plans or authorization rules**. Unsupported constructs may cause conservative overfetch or miss a prefetch opportunity; runtime resolvers must remain complete.

```php
use Keepsuit\Liquid\Analysis\DependencyPlanner;

$planner = new DependencyPlanner(
    fn (string $name) => $analysisEnvironment->parseString($sources[$name], $name),
);
$plan = $planner->analyse($analysisEnvironment->parseString($sources['grid'], 'grid'));

// Persist/cache $plan with the revision key described above.
$fields = $plan->fields('collection.products.*');
// id, image, price_formatted, title, url, variants (a relationship, not a SQL column)
```

`DependencyPlan::fields()` only returns direct field names. The adapter intersects these with a fixed schema allowlist, separates relationships from columns and binds all data values. Neither template field names nor the plan can inject SQL or select another tenant. The example's projection prefix is explicitly `collection.products.*`; mapping other application roots is adapter work.

## Bounded collection semantics

`ProvidesCollectionSlice` preserves PHP/Liquid array slicing: positive and negative offsets and lengths, null length, stable ordering and repeated reads. Both parsed and compiled loop paths use it before generic materialization; streaming loops use the same helpers. Reversal and `offset: continue` stay in the renderer. The example uses a count query only when bounds require total size, and caches that count within the request.

The collection adapter also supports `size`, `first`, `last` and positive/negative bracket indexing without hydrating the catalog. Out-of-range indexes retain strict-variable errors. Empty collections and zero-length windows remain empty.

**No generic filter-to-SQL rewriting is attempted.** Global `sort`, `where`, `map`, an unbounded `render ... for`, or full collection iteration can require the whole collection. The example refuses hydration beyond its page budget rather than filtering/sorting only an early page and silently changing the result. Applications can provide explicitly scoped, prefiltered collections when they can preserve Liquid semantics.

Default request budgets are 96 products per window, 2,048 fetched rows, 256 SQL statements and 8 MiB of projected scalar payload. Oversized requests raise `DataLoadingLimitException`, a resource-limit exception that aborts even when ordinary Liquid errors are tolerated. Unknown fields are not sent to SQL. Budgets count returned values; they do not bound database rows examined, statement execution time, or a single oversized database value before its fetch. Production adapters need database timeouts and schema/value limits as well.

## Run the experiment

Use PHP/Composer **inside Docker**, a Linux ARM64 runner with SQLite PDO, mbstring and OPcache, and its own local disk. The recorded runner uses the same PHP 8.4.23 Sail image, CPU affinity and VM as [the renderer comparison](../comparison/README.md). PHP dependencies are pinned in `../comparison/results/php-dependencies.lock`; no new dependencies were added.

1. Copy repository and vendor to `/bench` on the isolated container's filesystem. Exclude both PCOV and Xdebug from `PHP_INI_SCAN_DIR`; the harness refuses to time either extension. Use one CPU affinity and no CPU quota.
2. Run `php performance/Loading/run.php --setup` inside the container. It creates `build/loading-catalog.sqlite`: **2 tenants × 2,000 products × 4 variants**, with wide unused product/variant payloads. Existing databases are never overwritten. Fixtures are inserted row by row; no whole PHP catalog array is built.
3. Export reference inputs with `php -d opcache.enable_cli=1 -d opcache.file_update_protection=0 -d opcache.jit=disable performance/Loading/run.php --export-reference=build/loading-reference-inputs.json`.
4. Use Ruby Liquid **5.13.0**: `ruby performance/comparison/reference.rb build/loading-reference-inputs.json performance/Loading/results/reference-hashes.json`. Ruby can run on the host; transfer files as needed. All **48** contexts must validate. The reference inputs come from a separate eager array projection of actual database rows. Export mode does not use existing reference hashes.
5. Run `bash performance/Loading/run-series.sh` inside the runner. It alternates three fresh processes each without JIT and with tracing JIT. Each workload uses 8 warmup rounds and 24 measured rounds, with 16 personalized renders per batch. Four loading strategies rotate/reverse order. Build/test/profile work must not run concurrently.
6. Run `php performance/Loading/explain.php` to inspect the recorded query shapes. On SQLite the variants query should use `(tenant_id,product_id,id)`, ordering by `product_id,id`; this preserves order within each product while avoiding the scan induced by global `ORDER BY id`.
7. Copy `build/loading-results/*.json` out, then run `python3 performance/Loading/summarize.py RESULTS --prefix=final-plain- --output=summary-plain.json` and repeat for `final-jit-`. The summary rejects mixed source/database/reference identities or loaded profilers.

The four strategies share the same Drop renderer and repository instrumentation:

- `eager_full`: page of full product records plus all full variant records, even when variants are not displayed. It still loads only the selected page, making it a reasonable bounded baseline.
- `eager_projected`: planner-selected columns, with relationships fetched eagerly even when unused.
- `naive`: page IDs followed by full-record lazy product and variant queries per product, demonstrating N+1 behavior.
- `planned`: projected page query, lazy batched relationships, and batched fallback for fields whose names are dynamic.

Timing includes creating each fresh session/context, SQL preparation/execution/fetching, projection/normalization, cache population, Drop resolution and compiled rendering. Parsing/planning/compilation, connection establishment and independent correctness checks are outside timing. Query/row/byte instrumentation is inside timing and identical across strategies. This is a warm SQLite experiment; there is no fabricated database network latency.

CPU/wall values are batch means per render, summarized as median of fresh-process medians. They are **not request p95/p99**. Scalar payload bytes are measured value lengths (strings, 8 bytes per integer, zero for null); they exclude field names, protocol overhead and database pages scanned. Memory is a separate warm one-render PHP used-heap peak delta per mode, not allocator/RSS/SQLite-native memory. Aggregate process RSS includes reference validation across all modes and cannot rank strategies.

## Production boundary

The library code (`src/Analysis`, the slicing contract and resource exception) is reusable. Classes under `performance/Loading` are experiment adapters loaded through Composer's development autoloader, **not a production Laravel package**. They do not implement Shopify's complete object model, arbitrary relationship graphs, Eloquent integration, application authorization, snapshot/version consistency during concurrent updates, shared catalog caches or invalidation. Product/variant fields and SQL identifiers are an explicit fixed schema. Unknown/custom tags and filters must use application-owned resolvers.

A production the application adapter should own tenant/customer authorization, use consistent catalog versions or transactions across batches, and measure the real database/indexes, cache hit/miss mixes and concurrent HTTP requests. This experiment does not compare native Rust/WASM callbacks into Laravel; previous renderer-only results must not be extrapolated to that architecture.
