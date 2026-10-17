<?php

namespace Keepsuit\Liquid\Parse;

/** @internal Shared across the root parse and every static partial. */
final class ParseBudget
{
    public int $bytes = 0;

    public int $tokens = 0;
}
