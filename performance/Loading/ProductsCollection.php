<?php

namespace Keepsuit\Liquid\Performance\Loading;

use Keepsuit\Liquid\Contracts\ProvidesCollectionSlice;
use Keepsuit\Liquid\Drop;
use Keepsuit\Liquid\Exceptions\UndefinedDropMethodException;

/**
 * Explicit collection adapter; no transparent SQL rewriting of Liquid filters.
 *
 * @implements \IteratorAggregate<int, ProductDrop>
 */
final class ProductsCollection extends Drop implements \Countable, \IteratorAggregate, ProvidesCollectionSlice
{
    public function __construct(private readonly CatalogSession $session) {}

    /** @return list<ProductDrop> */
    public function liquidSlice(int $offset, ?int $length): array
    {
        return $this->session->page($offset, $length);
    }

    /** @return int<0, max> */
    public function size(): int
    {
        return $this->session->count();
    }

    /** @return int<0, max> */
    public function count(): int
    {
        return $this->size();
    }

    public function first(): ?ProductDrop
    {
        return $this->session->page(0, 1)[0] ?? null;
    }

    public function last(): ?ProductDrop
    {
        return $this->session->page(-1, 1)[0] ?? null;
    }

    protected function liquidMethodMissing(string $name): mixed
    {
        if (preg_match('/^-?\d+$/D', $name) === 1) {
            return $this->session->page((int) $name, 1)[0] ?? throw new UndefinedDropMethodException($name);
        }

        throw new UndefinedDropMethodException($name);
    }

    /** @return \Traversable<int, ProductDrop> */
    public function getIterator(): \Traversable
    {
        yield from $this->session->page(0, null);
    }
}
