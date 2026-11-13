<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Product;

final readonly class CartLine
{
    public function __construct(
        public Product $product,
        public int $quantity,
    ) {
    }

    public function getSubtotal(): int
    {
        return $this->product->getPrice() * $this->quantity;
    }
}
