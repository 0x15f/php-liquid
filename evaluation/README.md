# Initial measurements

Validation of the derivative on PHP 8.4.23: 3,828 tests passed with 10,137
assertions; PHPStan, Pint's changed-file check, and strict Composer validation
passed. Test run seed: `1792191862`. The 21 Shopify reference fixtures are checked
in both render and streaming modes against both PHP backends.

`upstream-render.json` records the unchanged upstream revision
`95508eb821e8375ac1992bf9c00470fccc120e64`, measured locally in the Sail PHP 8.4.23
ARM64 container with OPcache enabled and JIT/Xdebug disabled. Use
`performance/review-render.php` for the same workload. Its only later harness change
was a non-empty-sample assertion outside the timed regions.

Median batch averages were 1,989 microseconds/page interpreted and 1,844
microseconds/page compiled (ratio 1.079). The sample ranges were extremely wide:
1,316–8,039 microseconds interpreted and 1,100–4,636 compiled. These are wall-clock
measurements on a shared development machine, not reliable evidence of an engine
speedup, tail latency, CPU cost, or production capacity. No measured speedup is
claimed for the derivative's hardening changes.

The timing workload renders four upstream fixture pages using 29 templates, with
fresh fixture objects and contexts. It excludes Laravel and database/network work.
The warmup and alternating backend order are documented in ADOPTION.md. An initial
PHPBench run was stopped because variance-triggered retries were unproductive;
these numbers come from the bounded harness, not from that interrupted run.

Use an isolated production-like host and actual PHP and Laravel workloads before
making a PHP/native/WASM decision. A useful comparison must include all context
encoding, host callbacks, output copies, and worker initialization that the chosen
integration actually performs.
