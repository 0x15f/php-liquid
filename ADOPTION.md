# Compatibility and deployment notes

This derivative preserves upstream parsing and compilation, with request-output isolation, bounded work for empty bodies and Shopify reference fixes for shadowing, duplicate case matches and bracket pseudo-properties. Targeted fixtures use Ruby Liquid 5.13; this is not a complete Shopify hosted theme runtime.

Use fresh request contexts and narrow application-owned Drops, partition template caches by tenant and immutable revision, and rebuild compiled artifacts when the engine or extensions change. Work/output budgets are not complete memory or wall-clock isolation. Generated PHP classes remain loaded until worker exit. Validate real templates and deployment churn before adoption.

Performance experiments are synthetic and do not establish production capacity. Preserve upstream MIT attribution.
