#!/usr/bin/env bash
set -euo pipefail
comparison_dir="$(cd "$(dirname "$0")" && pwd)"
mkdir -p "$comparison_dir/artifacts"
docker run --rm --platform linux/arm64 \
  -v liquid-comparison-cargo:/usr/local/cargo \
  -v liquid-comparison-target:/target \
  -v "$comparison_dir:/work" -w /work/rust -e CARGO_TARGET_DIR=/target \
  rust@sha256:a339861ae23e9abb272cea45dfafde21760d2ce6577a70f8a926153677902663 \
  sh -c 'rustup target add wasm32-unknown-unknown && cargo test --release --locked && cargo build --release --locked && cargo build --release --locked --target wasm32-unknown-unknown && cp /target/release/libliquid_comparison.so /work/artifacts/ && cp /target/wasm32-unknown-unknown/release/liquid_comparison.wasm /work/artifacts/'
