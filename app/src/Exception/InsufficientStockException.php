<?php

declare(strict_types=1);

namespace App\Exception;

use App\Entity\ProductVariant;

/**
 * Levée quand la quantité demandée dépasse le stock disponible d'une variante.
 */
final class InsufficientStockException extends \DomainException
{
    public function __construct(public readonly ProductVariant $variant)
    {
        parent::__construct(sprintf(
            'Stock insuffisant pour « %s » : %d disponible(s).',
            $variant->getName(),
            $variant->getStock(),
        ));
    }
}
