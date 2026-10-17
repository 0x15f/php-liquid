<?php

namespace Keepsuit\Liquid\Nodes;

/** A literal bracket key must not invoke dot-only Liquid pseudo-properties. */
final readonly class BracketKey implements \Stringable
{
    public function __construct(public string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
