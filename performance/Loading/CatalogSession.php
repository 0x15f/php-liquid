<?php

namespace Keepsuit\Liquid\Performance\Loading;

use Keepsuit\Liquid\Analysis\DependencyPlan;
use Keepsuit\Liquid\Exceptions\DataLoadingLimitException;
use Keepsuit\Liquid\Exceptions\UndefinedDropMethodException;
use RuntimeException;

/** Construct once per render. Never retain this session in a worker/global cache. */
final class CatalogSession
{
    /** @var array<int, array<string, int|string|null>> */
    private array $products = [];

    /** @var array<int, list<VariantDrop>> */
    private array $variants = [];

    /** @var array<int, array<string, int|string|null>> */
    private array $variantRows = [];

    /** @var array<string, list<ProductDrop>> */
    private array $pages = [];

    /** @var int<0, max>|null */
    private ?int $count = null;

    public function __construct(
        public readonly CatalogStore $store,
        private readonly DependencyPlan $plan,
        private readonly string $mode = 'planned',
        private readonly int $maxPageSize = 96,
    ) {
        if (! in_array($mode, ['planned', 'naive', 'eager_full', 'eager_projected'], true)) {
            throw new RuntimeException('Unknown loading mode');
        }
    }

    /** @return list<ProductDrop> */
    public function page(int $offset, ?int $length): array
    {
        if ($offset < 0 || $length === null || $length < 0) {
            $count = $this->count();
            $offset = $offset < 0 ? max(0, $count + $offset) : min($offset, $count);
            $length = $length === null ? max(0, $count - $offset)
                : ($length < 0 ? max(0, $count - $offset + $length) : $length);
        }
        if ($length > $this->maxPageSize) {
            throw new DataLoadingLimitException('Page loading budget exceeded; paginate this collection');
        }
        if ($length === 0) {
            return [];
        }
        $key = $offset.':'.$length;
        if (isset($this->pages[$key])) {
            return $this->pages[$key];
        }
        $fields = match ($this->mode) {
            'naive' => ['id'],
            'eager_full' => CatalogStore::PRODUCT_FIELDS,
            default => $this->productFields(),
        };
        $rows = $this->store->products($offset, $length, $fields);
        $drops = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $this->products[$id] = [...($this->products[$id] ?? []), ...$row];
            $drops[] = new ProductDrop($this, $id);
        }
        if (str_starts_with($this->mode, 'eager_')) {
            $this->loadVariants(array_map(static fn (array $row): int => (int) $row['id'], $rows));
        }

        return $this->pages[$key] = $drops;
    }

    /** @return int<0, max> */
    public function count(): int
    {
        return $this->count ??= $this->store->count();
    }

    public function field(int $id, string $name): mixed
    {
        if ($name === 'variants') {
            if (! array_key_exists($id, $this->variants)) {
                $this->loadVariants($this->mode === 'naive' ? [$id] : array_keys($this->products));
            }

            return $this->variants[$id];
        }
        if (! in_array($name, CatalogStore::PRODUCT_FIELDS, true)) {
            throw new UndefinedDropMethodException($name);
        }
        if (! array_key_exists($name, $this->products[$id] ?? [])) {
            $ids = $this->mode === 'naive' ? [$id] : array_values(array_filter(array_keys($this->products), fn (int $key): bool => ! array_key_exists($name, $this->products[$key])));
            $fields = $this->mode === 'naive' ? CatalogStore::PRODUCT_FIELDS : [$name];
            foreach ($this->store->productsByIds($ids, $fields) as $row) {
                $rowId = (int) $row['id'];
                $this->products[$rowId] = [...$this->products[$rowId], ...$row];
            }
        }

        return $this->products[$id][$name];
    }

    public function variantField(int $id, string $name): mixed
    {
        if (! in_array($name, CatalogStore::VARIANT_FIELDS, true)) {
            throw new UndefinedDropMethodException($name);
        }
        if (! array_key_exists($name, $this->variantRows[$id])) {
            foreach ($this->store->variantsByIds(array_values(array_filter(array_keys($this->variantRows), fn (int $key): bool => ! array_key_exists($name, $this->variantRows[$key]))), [$name]) as $row) {
                $rowId = (int) $row['id'];
                $this->variantRows[$rowId] = [...$this->variantRows[$rowId], ...$row];
            }
        }

        return $this->variantRows[$id][$name];
    }

    /** @return list<string> */
    private function productFields(): array
    {
        return array_values(array_intersect($this->plan->fields('collection.products.*'), CatalogStore::PRODUCT_FIELDS));
    }

    /** @param list<int> $ids */
    private function loadVariants(array $ids): void
    {
        $ids = array_values(array_filter($ids, fn (int $id): bool => ! array_key_exists($id, $this->variants)));
        if ($ids === []) {
            return;
        }
        $fields = $this->mode === 'eager_full' || $this->mode === 'naive' ? CatalogStore::VARIANT_FIELDS
            : array_values(array_intersect($this->plan->fields('collection.products.*.variants.*'), CatalogStore::VARIANT_FIELDS));
        $fields = array_values(array_unique(['product_id', ...$fields]));
        $loaded = array_fill_keys($ids, []);
        foreach ($this->store->variants($ids, $fields) as $row) {
            $id = (int) $row['id'];
            $this->variantRows[$id] = $row;
            $loaded[(int) $row['product_id']][] = new VariantDrop($this, $id);
        }
        $this->variants += $loaded;
    }
}
