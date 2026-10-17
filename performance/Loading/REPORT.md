# Planned/lazy loading: measured PHP prototype

## Outcome

A compiled Liquid template can request a bounded product page and lazily batch the relationships it actually touches. The prototype demonstrates **one query for a 24-product grid without variants**, or **two queries when variants are rendered**, while keeping the catalog out of PHP memory. Dynamic field names use a bounded fallback batch rather than issuing one query per object.

The library now includes an AST `DependencyPlanner`, immutable `DependencyPlan`, opt-in `ProvidesCollectionSlice` contract and `DataLoadingLimitException`. The SQLite catalog/provider classes are **development-only experiment adapters**, not a production integration or complete Shopify object model. It builds on the [renderer comparison](../comparison/REPORT.md).

## Main results: tracing JIT

Linux ARM64, PHP 8.4.23, OPcache plus active tracing JIT, local file-backed SQLite. Each CPU cell is the median of three fresh-process medians: 24 measured batches after 8 warmup rounds, 16 personalized renders per batch, four loading strategies with rotated/reversed order. Timings include session/context setup, SQL, row decoding/validation, batching, caches, Drop access and compiled HTML rendering. Connection establishment, parsing/planning/compilation and correctness checks are outside timing. No database network latency is simulated.

| Workload | Strategy | CPU µs/render | Queries | Rows fetched | Scalar payload bytes | PHP peak delta KiB |
|---|---|---:|---:|---:|---:|---:|
| Grid without variants | Eager full page | 460.9 | 2 | 120 | 426,885 | 645.2 |
| Grid without variants | Eager projected page | 408.3 | 2 | 120 | 5,829 | 117.1 |
| Grid without variants | Naive lazy | 645.1 | 25 | 48 | 210,981 | 345.0 |
| Grid without variants | Planned + batched lazy | 210.1 | 1 | 24 | 1,701 | 48.4 |
| Grid with variants | Eager full page | 695.2 | 2 | 120 | 426,885 | 675.5 |
| Grid with variants | Eager projected page | 603.7 | 2 | 120 | 5,829 | 147.3 |
| Grid with variants | Naive lazy | 1,464.6 | 49 | 144 | 427,077 | 685.3 |
| Grid with variants | Planned + batched lazy | 610.6 | 2 | 120 | 5,829 | 147.3 |
| Dynamic field names | Eager full page | 526.5 | 2 | 120 | 426,885 | 630.2 |
| Dynamic field names | Eager projected page | 469.5 | 4 | 240 | 5,199 | 144.3 |
| Dynamic field names | Naive lazy | 1,204.6 | 49 | 144 | 427,077 | 634.5 |
| Dynamic field names | Planned + batched lazy | 466.7 | 4 | 240 | 5,199 | 144.3 |

The no-variants planned path uses approximately **54% less measured CPU**, **92.5% less warm PHP peak heap delta**, and **99.6% less scalar payload** than an already page-bounded eager full-record loader. It avoids both the variants query and the unused wide columns. This is not a comparison against loading an entire catalog into PHP; even the baseline fetches only the requested page.

With variants displayed, planned loading reduces **49 naive queries to 2**. Compared with eager full-page loading it transfers about **98.6% less scalar payload** and reduces warm PHP peak heap delta by **78.2%**; measured CPU is about **12% lower**. The existing two-query eager projected loader is effectively tied on CPU (604 vs 611 µs here). There is no evidence that adding laziness makes an already correct, fully consumed projection faster.

Dynamic field names show the tradeoff: the planned loader uses **four queries and 240 fetched rows**, versus two queries and 120 rows for eager full records, because the requested product/variant fields are discovered after their ID batches. It still transfers approximately 99% less scalar payload and uses less PHP heap. On a remote database those extra round trips can outweigh the local SQLite CPU result. A runtime-bound projection pass for resolvable dynamic keys is a possible next optimization, not implemented or claimed here.

These are descriptive local measurements, not production latency/capacity guarantees. Across strategy/workload combinations, process median CPU spread is up to **15.8% with JIT** and **13.4% without JIT**. Small timing differences should be treated as ties. Query counts, returned-row counts and value-byte counts are deterministic across all measured inputs/rounds. Raw wall times, per-process CPU medians and spreads are retained in the summaries. Batch means are not request p95/p99.

## Without JIT

CPU µs/render; all modes still use OPcache:

| Workload | Eager full | Eager projected | Naive lazy | Planned lazy |
|---|---:|---:|---:|---:|
| Grid without variants | 514.8 | 460.1 | 662.6 | 252.6 |
| Grid with variants | 812.1 | 694.3 | 1,477.9 | 701.1 |
| Dynamic field names | 570.9 | 519.2 | 1,219.5 | 528.9 |

## How it works

The planner scans the AST at template build/publication time, follows static snippets and known aliases, and records fields, loop hints and reasons runtime evaluation remains necessary. It never executes a discovery render or business-data query. Cache the immutable plan beside the compiled template under a version incorporating template/snippet, schema and resolver revisions. The benchmark builds plans once and reuses them across requests; it does not build plans in the timed path.

The critical renderer change is applying `for` bounds **before generic collection materialization** for providers implementing the slicing contract. The real offset/limit expressions are still evaluated at render time, so conditional values and `offset: continue` retain their semantics. Normal arrays, ranges and other collections retain their existing path. The provider preserves positive/negative offsets and lengths, reversal, empty windows and repeat reads. Aggregate size and first/last/bracket-index lookups are bounded as well.

The repository intersects field hints with fixed column allowlists. Every query binds a tenant ID; record IDs alone never identify a cross-tenant record. Each render gets a fresh session. Null and empty relation results are cached as values. Relationships and fallback columns batch only across records already selected in the request; they do not fetch the whole catalog.

Plans are advisory rather than complete: dynamic snippets/keys, custom filters, branch assignments and unsupported tags require runtime resolution. Conditional scalar columns may be conservatively prefetched even if their branch is skipped. Relationships remain deferred until accessed. This prototype's schema is deliberately limited to products and variants; arbitrary Shopify Drops/metafields need application-specific resolvers.

The example enforces page, query, fetched-row and scalar-byte budgets. Exceeding a budget aborts rendering even under a permissive Liquid error handler. Global sort/filter operations are not blindly rewritten into SQL or restricted to an early page; an oversized materialization fails explicitly. Returned-row budgets do not bound database rows examined or execution time, and byte budgets observe a value after fetching it. Production needs database statement limits and version/snapshot consistency across queries.

## Query-plan finding

An early pilot showed that `ORDER BY id` on a batched variants query made SQLite scan too much of the tenant's variant data. Ordering by **`product_id,id`** lets it use the existing `(tenant_id,product_id,id)` index while preserving order within each product's variant list. The corrected pilot was roughly an order of magnitude faster on the relation-heavy path. Early pilot timings are excluded from final results. [query-plans.json](results/query-plans.json) records `EXPLAIN QUERY PLAN` for both final query shapes; batching alone is not an index strategy.

## Dataset and validation

The database holds **4,000 products and 16,000 variants**, split across two tenants. Products include an unused approximately 8.5 KiB description; variants include an unused approximately 2 KiB payload. Fixture creation inserts one row at a time rather than building a PHP catalog array. Workloads request 24 products and vary customer IDs and tenants across 16 inputs each. One grid skips variants, one displays them, and one selects product/variant field names dynamically.

All **48** database-backed contexts, across all four strategies, match **Shopify Ruby Liquid 5.13.0** byte for byte before timing. The independent Ruby inputs come from a separate eager array projection of the actual database rows. A -> B -> A tenant/customer replay is covered by tests. This is fixture compatibility, not full Shopify platform compatibility.

Validation: **3,859 PHP tests / 10,286 assertions passed**, including bounded slicing in parsed/compiled/streamed output, nested snippet/assignment analysis, explicit argument precedence, dynamic fields, null caching, tenant isolation, negative/continued offsets, aggregate/index lookups and loading budgets under permissive error handlers. Full existing PHPStan scope passes **including this adapter and harness**, with no new exclusions; changed PHP files pass Pint. All six final result files identify the measured source snapshot and validated reference inputs; later integration changes have not been rebenchmarked.

The runner uses the same local Sail image as the renderer comparison, pinned to guest CPU 4 on an Apple M1 Pro / OrbStack Linux ARM64 VM, no cgroup CPU quota. PCOV and Xdebug are unloaded. The exact database, reference, fixture and source hashes are in each raw result. Database/native file cache and PHP code are warm; this is not a Graviton/server or concurrent HTTP benchmark. The existing PHP dependency lock is in `../comparison/results/php-dependencies.lock`.

Memory numbers are warm per-render **PHP used-heap peak deltas**, measured separately after timed rounds. They exclude native SQLite allocations, database server memory and OS caches. Shared process RSS includes reference setup/all strategies and cannot compare individual strategies. Scalar bytes count fetched value lengths (8 bytes for integers; null is zero), not protocol/wire bytes. Rows are returned rows, not database rows examined.

[README.md](README.md) provides the implementation boundaries and reproduction procedure. Raw samples, summaries, reference hashes and query plans are in [results/](results/). Before integration, implement a application-specific repository/resolver and repeat against its actual database/indexes, authorization, catalog versions, cache behavior and production ARM hardware. This experiment does not measure Rust/WASM callbacks into Laravel and does not revise that architecture ranking.
