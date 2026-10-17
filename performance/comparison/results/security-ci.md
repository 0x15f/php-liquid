# PHPBench release gate

Candidate `a480c86`, baseline `9d2aa0a` (v0.1.0 runtime). The unchanged 5% gate passes on all four PHP versions. [CI run](https://github.com/0x15f/php-liquid/actions/runs/39065166625).

<!-- phpbench-pr-benchmark-php-8.2 -->
## PHPBench comparison (PHP 8.2)

PHP 8.2 | Runner ubuntu24 | Base `9d2aa0a` | PR `a480c86` | 10 iterations x 20 revs | 1 warmup

> Positive ops/s is faster. RSD above 5% is marked high.

| Benchmark | Base ops/s | PR ops/s | Delta ops/s | RSD (base / PR) | Delta memory | Memory % |
|-----------|-----------:|---------:|------------:|----------------:|-------------:|---------:|
| ThemeBench::benchCompile | 105.26 ops/s | 127.22 ops/s | +20.87% | 0.64% / 0.88% | +10.45 KiB | +0.53% |
| ThemeBench::benchParse | 360.98 ops/s | 353.84 ops/s | -1.98% | 0.52% / 0.71% | +126.92 KiB | +1.50% |
| ThemeBench::benchRender | 260.02 ops/s | 257.19 ops/s | -1.09% | 0.61% / 1.43% | +101.18 KiB | +1.15% |
| ThemeBench::benchRenderCompiled | 307.35 ops/s | 302.47 ops/s | -1.59% | 1.12% / 2.13% | +36.65 KiB | +0.41% |
| ThemeBench::benchStream | 218.31 ops/s | 214.68 ops/s | -1.66% | 1.11% / 0.83% | +101.68 KiB | +1.27% |
| ThemeBench::benchStreamCompiled | 267.63 ops/s | 262.21 ops/s | -2.02% | 1.17% / 0.74% | +36.65 KiB | +0.46% |
| ThemeBench::benchTokenize | 722.92 ops/s | 708.01 ops/s | -2.06% | 1.50% / 1.53% | +73.30 KiB | +0.96% |

- Improved (> +2%): **1** (median +20.87%)
- Neutral (within ±2%): **4**
- Regressed (< -2%): **2** (median -2.04%)
- Worst throughput regression: **ThemeBench::benchTokenize (-2.06%)**
- Total memory change: **+0.94%**

- Regression threshold (`PHPBENCH_MAX_REG`): **5.00%**
- Threshold status: **PASSED**

<!-- phpbench-pr-benchmark-php-8.3 -->
## PHPBench comparison (PHP 8.3)

PHP 8.3 | Runner ubuntu24 | Base `9d2aa0a` | PR `a480c86` | 10 iterations x 20 revs | 1 warmup

> Positive ops/s is faster. RSD above 5% is marked high.

| Benchmark | Base ops/s | PR ops/s | Delta ops/s | RSD (base / PR) | Delta memory | Memory % |
|-----------|-----------:|---------:|------------:|----------------:|-------------:|---------:|
| ThemeBench::benchCompile | 105.24 ops/s | 126.30 ops/s | +20.01% | 0.92% / 0.92% | +12.30 KiB | +0.64% |
| ThemeBench::benchParse | 361.97 ops/s | 349.55 ops/s | -3.43% | 1.14% / 0.95% | +126.92 KiB | +1.50% |
| ThemeBench::benchRender | 260.55 ops/s | 257.86 ops/s | -1.03% | 1.80% / 1.13% | +37.18 KiB | +0.42% |
| ThemeBench::benchRenderCompiled | 308.72 ops/s | 303.32 ops/s | -1.75% | 1.83% / 0.79% | +101.78 KiB | +1.16% |
| ThemeBench::benchStream | 220.34 ops/s | 212.83 ops/s | -3.41% | 1.59% / 0.78% | +37.68 KiB | +0.47% |
| ThemeBench::benchStreamCompiled | 264.85 ops/s | 261.06 ops/s | -1.43% | 0.92% / 1.09% | +101.78 KiB | +1.30% |
| ThemeBench::benchTokenize | 722.72 ops/s | 715.89 ops/s | -0.94% | 0.94% / 1.05% | +73.30 KiB | +0.96% |

- Improved (> +2%): **1** (median +20.01%)
- Neutral (within ±2%): **4**
- Regressed (< -2%): **2** (median -3.42%)
- Worst throughput regression: **ThemeBench::benchParse (-3.43%)**
- Total memory change: **+0.95%**

- Regression threshold (`PHPBENCH_MAX_REG`): **5.00%**
- Threshold status: **PASSED**

<!-- phpbench-pr-benchmark-php-8.4 -->
## PHPBench comparison (PHP 8.4)

PHP 8.4 | Runner ubuntu24 | Base `9d2aa0a` | PR `a480c86` | 10 iterations x 20 revs | 1 warmup

> Positive ops/s is faster. RSD above 5% is marked high.

| Benchmark | Base ops/s | PR ops/s | Delta ops/s | RSD (base / PR) | Delta memory | Memory % |
|-----------|-----------:|---------:|------------:|----------------:|-------------:|---------:|
| ThemeBench::benchCompile | 204.92 ops/s | 245.51 ops/s | +19.81% | 0.56% / 1.48% | +10.45 KiB | +0.51% |
| ThemeBench::benchParse | 660.43 ops/s | 662.62 ops/s | +0.33% | 2.41% / 1.67% | +126.95 KiB | +1.50% |
| ThemeBench::benchRender | 526.16 ops/s | 517.61 ops/s | -1.63% | 0.65% / 1.13% | +37.18 KiB | +0.42% |
| ThemeBench::benchRenderCompiled | 627.92 ops/s | 611.70 ops/s | -2.58% | 1.06% / 1.52% | +100.71 KiB | +1.14% |
| ThemeBench::benchStream | 446.48 ops/s | 447.97 ops/s | +0.34% | 1.18% / 2.05% | +37.68 KiB | +0.47% |
| ThemeBench::benchStreamCompiled | 538.26 ops/s | 534.99 ops/s | -0.61% | 0.54% / 2.37% | +36.71 KiB | +0.46% |
| ThemeBench::benchTokenize | 1,389.73 ops/s | 1,390.37 ops/s | +0.05% | 1.67% / 1.25% | +73.30 KiB | +0.96% |

- Improved (> +2%): **1** (median +19.81%)
- Neutral (within ±2%): **5**
- Regressed (< -2%): **1** (median -2.58%)
- Worst throughput regression: **ThemeBench::benchRenderCompiled (-2.58%)**
- Total memory change: **+0.82%**

- Regression threshold (`PHPBENCH_MAX_REG`): **5.00%**
- Threshold status: **PASSED**

<!-- phpbench-pr-benchmark-php-8.5 -->
## PHPBench comparison (PHP 8.5)

PHP 8.5 | Runner ubuntu24 | Base `9d2aa0a` | PR `a480c86` | 10 iterations x 20 revs | 1 warmup

> Positive ops/s is faster. RSD above 5% is marked high.

| Benchmark | Base ops/s | PR ops/s | Delta ops/s | RSD (base / PR) | Delta memory | Memory % |
|-----------|-----------:|---------:|------------:|----------------:|-------------:|---------:|
| ThemeBench::benchCompile | 106.98 ops/s | 127.58 ops/s | +19.25% | 0.60% / 0.65% | +10.42 KiB | +0.49% |
| ThemeBench::benchParse | 369.52 ops/s | 359.70 ops/s | -2.66% | 0.69% / 1.22% | +126.91 KiB | +1.49% |
| ThemeBench::benchRender | 269.49 ops/s | 265.24 ops/s | -1.58% | 1.34% / 0.80% | +37.09 KiB | +0.41% |
| ThemeBench::benchRenderCompiled | 317.82 ops/s | 306.19 ops/s | -3.66% | 0.66% / 0.82% | +100.62 KiB | +1.13% |
| ThemeBench::benchStream | 226.07 ops/s | 221.89 ops/s | -1.85% | 0.86% / 1.02% | +37.59 KiB | +0.46% |
| ThemeBench::benchStreamCompiled | 270.50 ops/s | 263.13 ops/s | -2.72% | 0.56% / 1.06% | +36.62 KiB | +0.46% |
| ThemeBench::benchTokenize | 747.00 ops/s | 733.05 ops/s | -1.87% | 0.95% / 0.72% | +73.27 KiB | +0.95% |

- Improved (> +2%): **1** (median +19.25%)
- Neutral (within ±2%): **3**
- Regressed (< -2%): **3** (median -2.72%)
- Worst throughput regression: **ThemeBench::benchRenderCompiled (-3.66%)**
- Total memory change: **+0.81%**

- Regression threshold (`PHPBENCH_MAX_REG`): **5.00%**
- Threshold status: **PASSED**