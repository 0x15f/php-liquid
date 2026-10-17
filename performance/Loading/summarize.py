"""Summarize database + renderer batch CPU/wall time and measured loading metrics."""
import argparse
import json
import pathlib
import statistics as stats

parser = argparse.ArgumentParser()
parser.add_argument("directory", type=pathlib.Path)
parser.add_argument("--prefix", default="final-plain-")
parser.add_argument("--output", type=pathlib.Path)
args = parser.parse_args()
runs = [json.loads(p.read_text()) for p in sorted(args.directory.glob(args.prefix + "*.json"))]
if len(runs) < 3:
    raise SystemExit("Need at least three fresh-process results")
for run in runs:
    if any(run["profilers_loaded"].values()) or not all(c["shopify_reference"] for c in run["checks"].values()):
        raise SystemExit("Profiler loaded or missing reference validation")
for key in ("source_sha256", "database_sha256", "fixture_sha256", "reference_sha256"):
    if len({r[key] for r in runs}) != 1:
        raise SystemExit("Mixed sources or data: " + key)
if len({bool((r.get("jit_after") or {}).get("on")) for r in runs}) != 1:
    raise SystemExit("Mixed JIT modes")
report = {"runs": len(runs), "prefix": args.prefix, "rows": {}}
for workload, modes in runs[0]["samples"].items():
    report["rows"][workload] = {}
    for mode in modes:
        groups = [r["samples"][workload][mode] for r in runs]
        medians = [stats.median(s["cpu_us"] for s in g) for g in groups]
        row = {"cpu_us": stats.median(medians), "wall_us": stats.median(stats.median(s["wall_us"] for s in g) for g in groups),
               "cpu_run_medians": medians, "process_spread_pct": 100 * (max(medians) / min(medians) - 1),
               "php_peak_delta_bytes": stats.median(r["memory"][workload][mode]["php_peak_delta_bytes"] for r in runs)}
        for metric in ("queries", "rows", "payload_bytes", "output_bytes"):
            values = {s[metric] for g in groups for s in g}
            if len(values) != 1:
                raise SystemExit("Loading metrics unexpectedly vary: " + metric)
            row[metric] = values.pop()
        report["rows"][workload][mode] = row
        print(f"{workload:24} {mode:16} {row['cpu_us']:8.1f} us CPU  {row['queries']:2.0f} queries  {row['rows']:3.0f} rows  {row['payload_bytes']:7.0f} bytes  {row['php_peak_delta_bytes']:7.0f} PHP peak delta")
if args.output:
    args.output.write_text(json.dumps(report, indent=2) + "\n")
