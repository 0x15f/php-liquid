# Security

For vulnerabilities, use this repository's **Security → Report a vulnerability** flow when available. If private reporting is unavailable, contact the maintainer through their GitHub profile before posting exploit details publicly. Do not include credentials, customer data or private templates in public issues.

## Trust boundaries

Liquid templates do not directly execute arbitrary PHP expressions. PHP extensions, custom tags, filters, Drops, cache payloads and compiled artifacts are application code/infrastructure and must be trusted. Expose narrow Drops or prepared arrays, not a service container or arbitrary application objects. The Laravel view engine excludes framework execution objects that Laravel implicitly shares with PHP views.

HTML output is not automatically escaped. Use `escape` where untrusted values enter HTML; apply context-appropriate handling for URLs, JavaScript and other contexts.

Store compiled PHP outside web-accessible or untrusted-writable directories. Use application-controlled Redis/cache credentials and network access. Shared template serialization is not an untrusted message format. The shared cache never evaluates cached PHP source, but a compromised cache is outside the supported trust boundary.

Request contexts and personalized objects must be fresh. Cache namespaces isolate template versions; they are not authorization. Applications must select authorized tenants, filesystem roots and data, and publish consistent template/snippet revisions.

Output/work/resource budgets are useful limits, not a complete memory, wall-clock or database sandbox. Some operations materialize collections. Provider row/byte budgets count values after database reads, not database rows examined or statement duration. Use application/database limits and isolate workloads as appropriate.

Generated PHP classes stay loaded until the worker exits. Use bounded worker lifetimes and validate deployment/revision churn. Validate real templates and production cache configuration before rollout.
