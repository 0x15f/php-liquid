# ARM64 personalized HTML: compiled PHP vs native Rust vs WASM

## Decision

**Keep optimized compiled PHP as the candidate for the application's per-request PHP-array rendering path.** It wins every measured workload, even without PHP JIT. Tracing JIT improves it further. Native Rust is competitive for nested snippets; the tested WASM path is substantially slower. Do not integrate yet: run the same contract on the application's production ARM server class and actual merchant templates first.

This is the fastest **tested implementation under this input contract**, not proof that PHP beats every possible Rust renderer, native extension or WASM runtime. A second control below shows native Rust can win when its contexts already exist in native memory. Architecture and data ownership matter.

## Main results

CPU microseconds per completed render; smaller is better. Each cell is the median of three fresh-process medians, with 30 measured batches per process after 10 warmup rounds. OPcache is enabled throughout. Native and WASM columns below use the no-JIT series; their corresponding JIT-series observations are retained in the raw data and give the same ranking.

| Workload | PHP parsed | Starting compiled PHP | Optimized compiled PHP | Optimized PHP + JIT | Native Rust | Rust WASM |
|---|---:|---:|---:|---:|---:|---:|
| 4-card personalized fragment | 93.5 | 76.2 | 61.7 | 52.5 | 66.8 | 408.2 |
| 24-card nested grid | 545.4 | 437.9 | 358.7 | 287.2 | 392.8 | 2,186.4 |
| 96-card nested grid | 2,147.2 | 1,722.9 | 1,393.2 | 1,152.4 | 1,503.4 | 8,353.0 |
| 24-card inline control | 262.4 | 199.7 | 160.6 | 137.9 | 296.3 | 1,463.7 |

For the primary 24-card nested grid, PHP with JIT uses about **287 µs**, versus **393 µs native Rust** and **2,186 µs WASM**. This is about 1.37x and 7.61x as many renderer calls per unit CPU respectively, or 27% and 87% less renderer CPU per call. These figures exclude database access, application boot, HTTP, network and cache misses. They are not whole-application throughput or request tail latency.

Without JIT, optimized PHP's lead over native Rust on nested grids is approximately 8–10%, much smaller than its WASM lead. Main no-JIT fresh-process median spreads are 0.5–11.1% across engines/workloads; on the primary grid all engines are within 2.6%. PHP+JIT primary-grid spread is 3.4%. See summaries for every spread and raw wall measurements. CPU affinity in a VM is not exclusive ownership of a physical server core.

## Measured PHP improvement

The starting engine is this repository's commit `988a739c20e5e52adbf450f864ce5b4e17e4aa6e`, which already contains earlier compiler and compatibility work; it is **not unmodified Keepsuit upstream**. Its namespace is rewritten so baseline and candidate run in the same process with identical inputs. Paired-round ratios reduce temperature/frequency/order confounding. Intervals are descriptive cluster bootstraps over three processes and paired rounds, not a production hardware confidence guarantee.

| Workload | Without JIT speedup (95% interval) | CPU reduction | With JIT speedup (95% interval) | CPU reduction |
|---|---:|---:|---:|---:|
| 4-card personalized fragment | 1.226x (1.209–1.248) | 18.4% | 1.188x (1.176–1.198) | 15.8% |
| 24-card nested grid | 1.238x (1.218–1.248) | 19.2% | 1.218x (1.196–1.240) | 17.9% |
| 96-card nested grid | 1.237x (1.221–1.252) | 19.2% | 1.199x (1.191–1.221) | 16.6% |
| 24-card inline control | 1.235x (1.227–1.246) | 19.0% | 1.186x (1.167–1.203) | 15.7% |

Optimizations are deliberately stateless:

- Emit a single-key array lookup for the common compiled `product.title` path, avoiding path-array allocation and generic traversal.
- Resolve ordinary array/scalar steps directly, while retaining the generic behavior for nulls, objects, dynamic/bracket keys, negative indices and pseudo-properties.
- Resolve non-null innermost-scope values without opening the general scope iterator, while preserving null shadowing and deferred-object normalization.
- Dispatch compiled filters directly through the existing registry; emit integer output directly. Custom filter dispatch and Liquid conversion for other types remain intact.

An exploratory root-value cache was removed because it offered little extra benefit and introduced mutation/state complexity. No new cross-request mutable value cache is used. Xdebug profiling identified variable lookup, scope resolution, filter calls and partial-context work as hot paths; profile timings themselves are not performance results.

## What was actually compared

PHP 8.4.23 on Linux aarch64, Apple M1 Pro via OrbStack VM kernel 7.0.14; five guest CPUs with the runner pinned to guest CPU 4. No cgroup CPU quota or observed throttling. PHP dependencies are recorded in `results/php-dependencies.lock`. The locally built Sail image ID is in the reproduction instructions.

The Rust implementation is [liquid-rust](https://github.com/cobalt-org/liquid-rust), crate **0.26.11**, compiled with Rust **1.96.1**, release opt-level 3, fat LTO and one codegen unit. Native ARM64 and WASM use the same source, parser, standard library and cached parsed templates. WASM uses [Extism](https://github.com/extism/extism) **1.9.1** via its C ABI, PDK **1.4.1**, with a persistent warmed plugin. Native uses a persistent cached engine through PHP FFI. Neither starts a process or recompiles templates per request. Dependency locks and SHA256 fingerprints are retained.

The main contract starts from ready PHP arrays and ends with an HTML string. Rust measurements include PHP JSON encoding, native/WASM boundary calls, JSON decoding, conversion to Liquid values, rendering, output copies and deallocation. PHP renders those arrays directly. This reflects a Laravel application owning the data. Rust's decoder was optimized to parse JSON once into `serde_json::Value` and convert via `liquid::to_object`; directly deserializing Liquid's untagged model had avoidable overhead and was discarded before the final runs.

All engines preparse/precompile templates outside timing. PHP compiled templates use local disk and OPcache; both Rust paths cache ASTs. All calls return full HTML, which is consumed by length, rather than timing a no-op. Calibration uses full 16-customer cycles and targets 30 ms CPU per batch; large WASM batches can exceed that. Backend order rotates and reverses across rounds. Three plain and three tracing-JIT processes alternate. JIT status confirms it is actually active, with approximately 1 MB of generated code in a 64 MB buffer.

**Early exploratory timings with PCOV loaded were discarded.** Runtime-disabled coverage still affected interpretation. All final files explicitly show both PCOV and Xdebug unloaded, and the harness rejects timing if either is present. Diagnostic startup times and shared-process peak memory counters are retained but cannot rank cold starts or per-engine RSS.

## Workload and correctness

Four synthetic HTML contracts model the application product arrays, four variants per product, availability, escaped text/URLs, customer IDs/segments, wishlists and B2B-specific prices. Nested grids render a card snippet and nested price snippet per product; the inline control removes snippet contexts. There are 16 personalized inputs per workload, 64 total, plus A -> B -> A reuse checks. Inputs are approximately 2–49 KB of projected JSON, not complete database records. Prices are preformatted upstream; Shopify money filters are not benchmarked.

Shopify's [Ruby Liquid](https://github.com/Shopify/liquid) **5.13.0** is the output reference. All 64 outputs match it byte for byte for parsed PHP, both compiled PHP versions, native Rust and WASM before timing. These synthetic cases do not substitute for actual theme traces or Shopify's server-provided objects/tags.

The separate edge-case review probe passes **12/21 cases** for both Rust targets. Failures include missing/nil property behavior, duplicate `case` matches, quoted bracket pseudo-properties, `default: ..., allow_false: true`, decimal arithmetic, negative division/modulo, `offset: continue` and numeric coercion. See `results/rust-conformance.json` for exact expected/actual output and errors. This is a deliberately adversarial probe, not a compatibility percentage. PHP's existing Shopify review fixtures and full library suite pass. Neither set establishes complete Shopify theme-platform compatibility.

## Data ownership control

CPU µs per render, same-process controls (one process per mode):

| Workload | PHP compiled, no JIT | Native preloaded, no JIT | PHP compiled + JIT | Native preloaded, JIT process | WASM preloaded, no JIT |
|---|---:|---:|---:|---:|---:|
| fragment_4 | 62.5 | 41.3 | 52.4 | 40.5 | 297.3 |
| grid_24 | 350.6 | 247.4 | 292.4 | 246.2 | 1,656.1 |
| grid_96 | 1,432.1 | 1,030.6 | 1,151.3 | 983.4 | 6,695.4 |
| inline_24 | 159.8 | 152.5 | 132.9 | 150.8 | 1,035.3 |


The prepared control stores all 64 Liquid contexts inside the native/WASM engine before timing, sending only a template name and input index per call. It removes per-request context construction and most input serialization. This is a single fresh-process diagnostic per PHP mode, not part of the three-process main ranking. It shows why “Rust is intrinsically slower” would be the wrong conclusion: native rendering can win if the application can keep the relevant product/customer state in native memory cheaply. A real reusable-catalog implementation would still need to measure personalization overlays, invalidation, memory, lifecycle and update costs; the indexed control pays none of those.

## Validation and next decision

- Full PHP suite: **3,834 tests, 10,149 assertions passed**; new tests exercise fast-path fallback, strict missing/null behavior, lazy-object normalization and streamed output on both PHP backends.
- Normal PHPStan library/existing performance scope passes; changed PHP files pass Pint. The standalone FFI/generated-baseline harness has an explicit separate analysis exclusion and is checked through runtime parity/smoke runs.
- Rust release unit test verifies repeated personalized calls do not share assignments; both release targets build from the lock file.
- Source fingerprints match the final local PHP implementation. the application's checkout is unchanged.

Before integration, replay real product grids/snippets on the production ARM server class with production PHP/FPM or Octane settings. Measure cache hit/miss mixes, personalization cardinality, concurrent HTTP p50/p95/p99, worker RSS, cold deploys and template invalidation separately. If most context data can stay native across requests, benchmark a catalog-plus-personalization API before dismissing native Rust. Otherwise compiled PHP is the evidence-supported default; WASM provides no speed advantage in this experiment.

Raw data, reference hashes, locks and summaries are in [results/](results/). [README.md](README.md) describes reproduction and measurement boundaries. No the application integration or deployment is included.
