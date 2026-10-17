<?php

namespace Keepsuit\Liquid\Performance\Loading;

use Keepsuit\Liquid\Drop;

final class VariantDrop extends Drop
{
    public function __construct(private readonly CatalogSession $session, private readonly int $id) {}

    protected function liquidMethodMissing(string $name): mixed
    {
        return $this->session->variantField($this->id, $name);
    }
}
