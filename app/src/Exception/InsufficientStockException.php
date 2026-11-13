<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\Product;

/**
 * Levée quand la quantité demandée dépasse le stock disponible.
 */
final class InsufficientStockException extends \DomainException
{
    public function __construct(public readonly Product $product)
    {
        parent::__construct(sprintf(
            'Stock insuffisant pour « %s » : %d disponible(s).',
            $product->getName(),
            $product->getStock(),
        ));
    }
}
