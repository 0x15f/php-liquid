<?php

namespace Keepsuit\Liquid\Support;

use IteratorAggregate;
use Traversable;

/**
 * Normalizes standard array-filter input without flattening hashes or iterators.
 *
 * @implements IteratorAggregate<int, mixed>
 *
 * @internal
 */
class FilterInputIterator implements IteratorAggregate
{
    private int $visited = 0;

    public function __construct(
        protected mixed $input,
        protected FilterSupport $support,
    ) {}

    public function getIterator(): Traversable
    {
        $this->visited = 0;
        if ($this->input instanceof UndefinedVariable) {
            throw $this->input->toException();
        }

        if (is_array($this->input) && array_is_list($this->input)) {
            return $this->flatten($this->input);
        }

        $input = match (true) {
            $this->input === null => [],
            $this->input instanceof Traversable => $this->input,
            default => [$this->input],
        };

        assert(is_iterable($input));

        return $this->normalize($input);
    }

    protected function normalize(iterable $input): Traversable
    {
        foreach ($input as $value) {
            if ($this->support->bounded) {
                $this->support->visit(++$this->visited);
            }
            yield $this->support->normalize($value);
        }
    }

    protected function flatten(array $input, int $depth = 0): Traversable
    {
        foreach ($input as $value) {
            if ($this->support->bounded) {
                $this->support->visit(++$this->visited, $depth);
            }
            if (is_array($value) && array_is_list($value)) {
                foreach ($this->flatten($value, $depth + 1) as $item) {
                    yield $item;
                }
            } else {
                yield $this->support->normalize($value);
            }
        }
    }
}
