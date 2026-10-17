"""Summarize raw batch CPU observations; never label batch means as request p99."""
import argparse
import json
import pathlib
import random
import statistics as stats

parser = argparse.ArgumentParser()
parser.add_argument("directory", type=pathlib.Path)
parser.add_argument("--prefix", default="final-plain-")
parser.add_argument("--output", type=pathlib.Path)
args = parser.parse_args()
runs = [json.loads(p.read_text()) for p in sorted(args.directory.glob(args.prefix + "*.json"))]
if not runs:
    raise SystemExit("No completed runs")
for run in runs:
    if any(run["profilers_loaded"].values()):
        raise SystemExit("Profiler-loaded samples cannot be used")
    if not all(c["shopify_reference"] for c in run["checks"].values()):
        raise SystemExit("Missing Shopify output validation")
for key in ("fixture_sha256", "engine_source_sha256", "rust_source_sha256", "cargo_lock_sha256", "native_sha256", "wasm_sha256", "input_mode"):
    if len({r[key] for r in runs}) != 1:
        raise SystemExit("Mixed fixture, engine, artifact or input modes: " + key)
if len({bool((r.get("jit_after") or {}).get("on")) for r in runs}) != 1:
    raise SystemExit("Mixed JIT modes")

def interval(groups):
    rng = random.Random(20261017)
    estimates = []
    # Cluster bootstrap: resample fresh processes, then paired rounds within each.
    for _ in range(3000):
        values = []
        for _ in groups:
            group = rng.choice(groups)
            values.extend(rng.choices(group, k=len(group)))
        estimates.append(stats.median(values))
    estimates.sort()
    return [estimates[74], estimates[2924]]

report = {"runs": len(runs), "prefix": args.prefix, "rows": {}}
for workload in runs[0]["samples"]:
    report["rows"][workload] = {}
    for backend in runs[0]["samples"][workload]:
        samples = [r["samples"][workload][backend] for r in runs]
        medians = [stats.median(s["cpu_us"] for s in group) for group in samples]
        walls = [stats.median(s["wall_us"] for s in group) for group in samples]
        row = {"cpu_us": stats.median(medians), "wall_us": stats.median(walls), "run_medians_cpu_us": medians,
               "process_spread_pct": 100 * (max(medians) / min(medians) - 1)}
        if "php_compiled_baseline" in runs[0]["samples"][workload]:
            ratios = []
            for run, group in zip(runs, samples):
                baseline = {s["round"]: s["cpu_us"] for s in run["samples"][workload]["php_compiled_baseline"]}
                ratios.append([baseline[s["round"]] / s["cpu_us"] for s in group])
            row["speedup_vs_frozen_php"] = stats.median([v for group in ratios for v in group])
            row["speedup_ci95"] = interval(ratios)
            row["run_speedups"] = [stats.median(g) for g in ratios]
        report["rows"][workload][backend] = row
        print(f"{workload:12} {backend:23} {row['cpu_us']:9.2f} us CPU  process spread {row['process_spread_pct']:5.1f}%")
if args.output:
    args.output.write_text(json.dumps(report, indent=2) + "\n")
