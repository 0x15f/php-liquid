<?php

namespace Keepsuit\Liquid\Performance\Loading;

use Keepsuit\Liquid\Exceptions\DataLoadingLimitException;
use PDO;
use RuntimeException;

/** SQLite experiment adapter: explicit schema, tenant scope and per-request budgets. */
final class CatalogStore
{
    public const PRODUCT_FIELDS = ['id', 'title', 'url', 'image', 'price_formatted', 'available', 'description', 'extra'];

    public const VARIANT_FIELDS = ['id', 'product_id', 'title', 'available', 'extra'];

    public int $queries = 0;

    public int $rows = 0;

    public int $bytes = 0;

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $tenant,
        private readonly int $maxRows = 2048,
        private readonly int $maxQueries = 256,
        private readonly int $maxBytes = 8_388_608,
    ) {}

    /** @param list<string> $fields
     * @return list<array<string, int|string|null>>
     */
    public function products(int $offset, int $limit, array $fields): array
    {
        return $this->select('products', $fields, self::PRODUCT_FIELDS,
            'ORDER BY id LIMIT ? OFFSET ?', [$limit, $offset]);
    }

    /** @param list<int> $ids
     * @param  list<string>  $fields
     * @return list<array<string, int|string|null>>
     */
    public function productsByIds(array $ids, array $fields): array
    {
        return $this->byIds('products', 'id', $ids, $fields, self::PRODUCT_FIELDS);
    }

    /** @param list<int> $ids
     * @param  list<string>  $fields
     * @return list<array<string, int|string|null>>
     */
    public function variants(array $ids, array $fields): array
    {
        return $this->byIds('variants', 'product_id', $ids, $fields, self::VARIANT_FIELDS);
    }

    /** @param list<int> $ids
     * @param  list<string>  $fields
     * @return list<array<string, int|string|null>>
     */
    public function variantsByIds(array $ids, array $fields): array
    {
        return $this->byIds('variants', 'id', $ids, $fields, self::VARIANT_FIELDS);
    }

    /** @return int<0, max> */
    public function count(): int
    {
        $this->beginQuery();
        $query = $this->pdo->prepare('SELECT COUNT(*) FROM products WHERE tenant_id = ?');
        $query->execute([$this->tenant]);

        return max(0, (int) $query->fetchColumn());
    }

    /** @param list<int> $ids
     * @param  list<string>  $fields
     * @param  list<string>  $allowed
     * @return list<array<string, int|string|null>>
     */
    private function byIds(string $table, string $key, array $ids, array $fields, array $allowed): array
    {
        if ($ids === []) {
            return [];
        }
        $markers = implode(',', array_fill(0, count($ids), '?'));

        return $this->select($table, $fields, $allowed,
            'AND '.$key.' IN ('.$markers.') ORDER BY '.($key === 'product_id' ? 'product_id,id' : 'id').' LIMIT ?', [...$ids, $this->maxRows - $this->rows + 1]);
    }

    /** @param list<string> $fields
     * @param  list<string>  $allowed
     * @param  list<int>  $parameters
     * @return list<array<string, int|string|null>>
     */
    private function select(string $table, array $fields, array $allowed, string $suffix, array $parameters): array
    {
        $fields = array_values(array_unique(['id', ...$fields]));
        if (array_diff($fields, $allowed) !== []) {
            throw new RuntimeException('Unknown projected field');
        }
        $this->beginQuery();
        // Table/key/suffix come only from the fixed methods above; template input
        // cannot supply SQL identifiers. Every query carries the tenant predicate.
        $query = $this->pdo->prepare('SELECT '.implode(',', $fields).' FROM '.$table.' WHERE tenant_id = ? '.$suffix);
        foreach ([$this->tenant, ...$parameters] as $i => $value) {
            $query->bindValue($i + 1, $value, PDO::PARAM_INT);
        }
        $query->execute();
        $result = [];
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
            if (++$this->rows > $this->maxRows) {
                throw new DataLoadingLimitException('Row loading budget exceeded');
            }
            $checked = [];
            foreach ($row as $key => $value) {
                if (! is_string($key) || (! is_int($value) && ! is_string($value) && $value !== null)) {
                    throw new RuntimeException('Unexpected catalog value type');
                }
                $checked[$key] = $value;
                $this->bytes += is_string($value) ? strlen($value) : ($value === null ? 0 : 8);
            }
            if ($this->bytes > $this->maxBytes) {
                throw new DataLoadingLimitException('Byte loading budget exceeded');
            }
            $result[] = $checked;
        }
        $query->closeCursor();

        return $result;
    }

    private function beginQuery(): void
    {
        if (++$this->queries > $this->maxQueries) {
            throw new DataLoadingLimitException('Query loading budget exceeded');
        }
    }
}
