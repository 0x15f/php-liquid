<?php

namespace Keepsuit\Liquid\Performance\Loading;

use Keepsuit\Liquid\Drop;

final class ProductDrop extends Drop
{
    public function __construct(private readonly CatalogSession $session, private readonly int $id) {}

    protected function liquidMethodMissing(string $name): mixed
    {
        return $this->session->field($this->id, $name);
    }
}
