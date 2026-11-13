<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Product;
use App\Entity\ProductVariant;

final readonly class CartLine
{
    public function __construct(
        public ProductVariant $variant,
        public int $quantity,
    ) {
    }

    public function getProduct(): Product
    {
        return $this->variant->getProduct() ?? throw new \LogicException('Variante sans produit.');
    }

    public function getUnitPrice(): int
    {
        return $this->variant->getEffectivePrice();
    }

    public function getSubtotal(): int
    {
        return $this->getUnitPrice() * $this->quantity;
    }
}
