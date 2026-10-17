<?php

namespace Keepsuit\Liquid\Contracts;

/**
 * Opt-in bounded collection access for `for`, before generic materialization.
 * Implementations must preserve array_slice semantics (including negative
 * offset/length and null length), stable ordering and repeatable reads.
 * Reversal and offset:continue are handled by the renderer after slicing.
 * Other Liquid operations are not automatically translated into database queries.
 */
interface ProvidesCollectionSlice
{
    /** @return list<mixed> */
    public function liquidSlice(int $offset, ?int $length): array;
}
