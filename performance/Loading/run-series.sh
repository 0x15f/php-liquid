#!/usr/bin/env bash
# Inside an isolated Linux ARM64 PHP container, with Xdebug/PCOV unloaded.
set -euo pipefail
cd "$(dirname "$0")/../.."
mkdir -p build/loading-results
flags=(-d opcache.enable_cli=1 -d opcache.file_update_protection=0)
for i in 1 2 3; do
  php "${flags[@]}" -d opcache.jit=disable performance/Loading/run.php \
    --rounds=24 --warmup=8 --batch=16 --output="build/loading-results/final-plain-$i.json"
  php "${flags[@]}" -d opcache.jit=tracing -d opcache.jit_buffer_size=64M performance/Loading/run.php \
    --rounds=24 --warmup=8 --batch=16 --output="build/loading-results/final-jit-$i.json"
done
python3 performance/Loading/summarize.py build/loading-results --prefix=final-plain- --output=build/loading-results/summary-plain.json
python3 performance/Loading/summarize.py build/loading-results --prefix=final-jit- --output=build/loading-results/summary-jit.json
