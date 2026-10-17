# Restricted PHP rendering and stable streaming

These are local synthetic measurements, not production capacity estimates. The workload is a personalized 24-product HTML grid with four variants per product and nested card/price snippets, covering all 16 customer inputs. Every output is checked against the committed Shopify Ruby Liquid reference hashes.

## Runtime and method

- Linux ARM64 (`aarch64`), PHP 8.4.26, local container with a two-CPU quota.
- Three fresh processes per cohort; 51 measured rounds after 15 warm-up rounds. Core modes alternate in balanced/reversed batches in the same process. Timing uses process CPU time, with no profilers loaded. The default cohort has JIT disabled; a separate cohort enables tracing JIT.
- Warm parsed/compiled caches, fresh render contexts and complete prepared PHP input arrays for every request. Authorization, Laravel/HTTP bootstrap, database work, source retrieval, Redis/network misses and compilation are outside this core timing.
- Baseline commit `9d2aa0a` has the same `src/` tree as public v0.1.0. It is loaded under a separate namespace for paired timing.
- All nine core/isolated timing result files were captured at revision `3fd1ac5` with engine source SHA-256 `b5a824b4fa67441f9f989821b63d884b7424cc8edc6b9ffeb36022968fff90e2`.

## Core rendering

Reported values are the median of three process medians; ranges show those three medians. Lower CPU time is better.

| Mode | CPU µs/render | Process-median range |
| --- | ---: | ---: |
| v0.1.0 compiled baseline, without restrictions | 378 | 356–383 |
| Current compiled compatibility mode | 439 | 416–439 |
| Current interpreted compatibility mode | 643 | 616–655 |
| Restricted compiled PHP | **745** | 699–749 |
| Restricted compiled streaming, fully consumed | 800 | 749–814 |
| Restricted interpretation | 947 | 902–964 |

Compiled PHP is about 21% faster than the restricted interpreter on this workload. Fully consumed streaming costs about 7% more than restricted string rendering when comparing these aggregate medians. The streaming timing includes concatenation by the benchmark consumer so output equality can be checked; a network consumer need not build that final string.

Security is not free: the restricted profile uses about twice the CPU of the v0.1.0 baseline. It validates input structure/capabilities, binds cache authorization and meters work, allocations and deadlines. The compatibility-mode regression also remains visible above; it is not hidden by comparing only the restricted implementations. Earlier native Rust/WASM figures in REPORT.md do not measure equivalent security policies and should not be used to claim a secure-backend winner.

Raw core results: [run 1](results/security-core-1.json), [run 2](results/security-core-2.json), [run 3](results/security-core-3.json).

### Tracing JIT

A separate three-process cohort uses CLI OPcache with a 128 MiB JIT buffer and `opcache.jit=tracing`. Metadata confirms JIT was enabled and active. All reference output hashes match.

| Mode | CPU µs/render | Process-median range |
| --- | ---: | ---: |
| v0.1.0 compiled baseline, without restrictions | 381 | 377–383 |
| Current compiled compatibility mode | 374 | 368–382 |
| Current interpreted compatibility mode | 517 | 503–546 |
| Restricted compiled PHP | **657** | 641–671 |
| Restricted compiled streaming, fully consumed | 713 | 710–733 |
| Restricted interpretation | 826 | 825–873 |

Restricted compiled rendering uses about 12% less CPU than the non-JIT cohort and about 20% less than the JIT restricted interpreter. Fully consumed streaming costs about 9% more than JIT restricted string rendering. Cross-cohort differences can include host variation; the paired modes within each cohort are the stronger comparison. The compatibility-mode overhead seen without JIT is not apparent in this JIT cohort.

JIT is an application deployment choice, not an INI change made by the library. These measurements cover PHP 8.4.26 on ARM64; verify your production PHP build. OPcache/JIT memory sits outside the PHP heap allowance and needs separate deployment accounting. The minimal isolated worker deliberately disables CLI OPcache/JIT. The security suite also passes with function/loop hot thresholds set to 1, including a warmed small range followed by a billion-item range that must fail before allocation.

Raw JIT results: [run 1](results/security-jit-1.json), [run 2](results/security-jit-2.json), [run 3](results/security-jit-3.json).

### CI compatibility regression gate

At PR revision `88b0c3a`, all PHP 8.2–8.5 correctness jobs (including hot JIT), Laravel 12/13 Redis integration, static analysis, formatting and production-only installation pass. Compiler-emission optimization preserves byte-identical generated PHP and brings compilation throughput to unchanged–4% faster than v0.1.0. The separate non-JIT PHPBench compatibility canary still fails its existing 5% threshold: compiled rendering loses 10–14% throughput, and streaming loses 9–15%. These are a different storefront workload and x86 CI machines, not the ARM64 grid measurements above.

The performance gate has not been weakened. Subsequent changes specialize compilation profiles: guarded artifacts retain allocation checks, unrestricted artifacts reject bounded contexts, and cache identities distinguish the modes. Immutable data-access decisions, filter dispatch and buffer appends also have fast paths. Their final CI and refreshed ARM64 measurements are pending. See the [PR's benchmark comments](https://github.com/0x15f/php-liquid/pull/1) for complete paired results.


## Isolated PHP worker

This separate benchmark measures parent-observed **wall time**, including JSON validation/encoding, IPC, child rendering and output transfer. It does not report parent-only CPU as if it included child CPU. Each process performs a cold request, 32 warm-up requests and 320 measured requests over all 16 customers. The child has a 128 MiB PHP heap allowance, a maximum of 100 requests, private compiled caches and CLI OPcache disabled. Measured samples include automatic worker recycling. The development Composer autoloader is used; production-only startup can differ.

| Metric | Three-process result |
| --- | ---: |
| Median request wall time | 1.44–1.55 ms; median of medians **1.50 ms** |
| Mean wall time, including recycling | 2.56–2.91 ms |
| p95 within each process | 3.18–4.24 ms |
| First cold worker request | 94.0–128.7 ms |
| Largest measured request, including recycling | 83.8–156.4 ms |

Worker startup/recycling is a real tail cost. Reuse workers, and tune lifetime against observed heap usage and template revision churn. These sequential measurements do not establish concurrency, HTTP first-byte latency or fleet capacity. Approved lazy database adapters belong in the in-process restricted API inside an externally limited application worker; the minimal isolated worker accepts JSON values and does not bootstrap Laravel or database adapters.

Raw isolated results: [run 1](results/security-isolated-1.json), [run 2](results/security-isolated-2.json), [run 3](results/security-isolated-3.json).

## Streaming heap use

A separate restricted compiled template emits 2,000 copies of a 4,096-byte value: **8,192,000 output bytes**. Compilation and input setup are excluded from peak heap deltas. The stream is hashed and counted incrementally, and its hash must equal string rendering.

| Consumer | Peak PHP allocated heap delta |
| --- | ---: |
| Complete rendered string | 8,333,840 bytes |
| Incremental stream consumption | **42,640 bytes** |

That is a 99.5% reduction for this deliberately large response, not a general memory guarantee. It is PHP allocated heap, not RSS. Captures, filters, source loading and individual nodes can still materialize intermediate values within their configured limits. See [raw memory result](results/security-stream-memory.json).

## Verification and reproduction

The release gate passes 4,009 tests / 24,148 assertions with real Redis, PHPStan and Pint. Streaming is checked against all 64 personalized Shopify reference cases across interpreted/compiled and restricted/compatibility execution. Adversarial tests cover range/string/collection growth, parser depth and token limits, PHP capabilities, cache authorization, global callback isolation, data-provider budgets, parent transport expansion, worker heap exhaustion, deadlines, cancellation, recovery and bounded private-cache disk retention.

Prepare the baseline with Python and Git on the host (or in a container with both installed):

```bash
python3 performance/comparison/prepare-baseline.py --revision 9d2aa0a
cp performance/comparison/results/reference-hashes.json build/reference-hashes.json
```

Then run inside the repository's PHP development container:

```bash
php -d opcache.enable_cli=1 performance/comparison/run.php \
  --php-only --security --paired-baseline --workload=grid_24 \
  --rounds=51 --warmup=15 --output=/tmp/core.json
php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=128M -d opcache.jit=tracing \
  performance/comparison/run.php --php-only --security --paired-baseline \
  --workload=grid_24 --rounds=51 --warmup=15 --output=/tmp/jit.json
php performance/comparison/isolated.php --samples=320 --output=/tmp/isolated.json
php performance/comparison/stream-memory.php
```

Repeat each timing command in three fresh processes and inspect output hashes and source fingerprints. `prepare-baseline.py` needs Python and Git; it can run on the host when the PHP image does not include Python. PHP/Composer execution for these results ran in Docker.
