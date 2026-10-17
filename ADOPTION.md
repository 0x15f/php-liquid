# Compatibility and deployment notes

This derivative preserves the upstream parser and compiler, with request-output isolation, bounded work for empty bodies, and Shopify reference fixes for root shadowing, duplicate `case` matches and bracket pseudo-properties. Targeted output fixtures use Shopify Ruby Liquid 5.13; they are not an exhaustive platform conformance suite.

- Shopify platform objects, sections, orchestration and money/translation/asset filters require an application integration. The deprecated `include` tag is unsupported; use `render`.
- Parsing is strict. Runtime strict-variable/filter/error settings do not relax parser syntax.
- Use immutable template/snippet revisions and fresh render contexts. Personalized Drops and data-loading sessions belong to the request.
- Compiled cache identities must include tenant/template revision, engine/compiler revision and extension configuration. The standalone cache classes use template names within their directory; partition directories explicitly. The Laravel integration supplies revision/namespace identities.
- Generated PHP classes cannot unload individually. Use bounded worker lifetimes and measure revision churn.
- Output/work budgets are not a complete memory or wall-clock sandbox. Some ranges/filters allocate before work limits fire. Provider budgets do not constrain database execution time or rows scanned.
- Keep a compatible previous backend/revision available while validating real templates and cold/warm behavior.

See [Laravel configuration](LARAVEL.md), [security guidance](SECURITY.md), [renderer measurements](performance/comparison/REPORT.md) and [bounded loading measurements](performance/Loading/REPORT.md).
