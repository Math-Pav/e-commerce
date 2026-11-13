<?php

declare(strict_types=1);

namespace App\Api\Output;

/**
 * Une ligne de commande : nom et prix copiés au moment de l'achat.
 */
final class OrderItemOutput
{
    public function __construct(
        public readonly string $productName,
        public readonly ?string $variantLabel,
        public readonly Price $unitPrice,
        public readonly int $quantity,
        public readonly Price $subtotal,
    ) {
    }
}
