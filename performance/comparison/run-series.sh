#!/usr/bin/env bash
# Execute INSIDE a dedicated ARM64 container, with PHP_INI_SCAN_DIR already
# pointing to a config directory which excludes both PCOV and Xdebug.
set -euo pipefail
cd "$(dirname "$0")/../.."
mkdir -p build/comparison
php_flags=(-d opcache.enable_cli=1 -d opcache.file_update_protection=0 -d ffi.enable=1)
for i in 1 2 3; do
  php "${php_flags[@]}" -d opcache.jit=disable performance/comparison/run.php \
    --paired-baseline --rounds=30 --warmup=10 --label="final-plain-$i" --output="build/comparison/final-plain-$i.json"
  php "${php_flags[@]}" -d opcache.jit=tracing -d opcache.jit_buffer_size=64M performance/comparison/run.php \
    --paired-baseline --rounds=30 --warmup=10 --label="final-jit-$i" --output="build/comparison/final-jit-$i.json"
done
php "${php_flags[@]}" -d opcache.jit=disable performance/comparison/run.php \
  --paired-baseline --prepared --rounds=30 --warmup=10 --label=prepared-control --output=build/comparison/prepared-control.json
php "${php_flags[@]}" -d opcache.jit=tracing -d opcache.jit_buffer_size=64M performance/comparison/run.php \
  --paired-baseline --prepared --rounds=30 --warmup=10 --label=prepared-jit-control --output=build/comparison/prepared-jit-control.json
php -d ffi.enable=1 performance/comparison/check-reference.php > build/comparison/rust-conformance.json
python3 performance/comparison/summarize.py build/comparison --prefix=final-plain- --output=build/comparison/summary-plain.json
python3 performance/comparison/summarize.py build/comparison --prefix=final-jit- --output=build/comparison/summary-jit.json
