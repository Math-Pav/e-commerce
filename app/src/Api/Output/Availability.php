<?php

declare(strict_types=1);

namespace App\Api\Output;

use App\Entity\Product;
use App\Entity\ProductVariant;

/**
 * Disponibilité exposée par l'API : un état, jamais le stock exact (donnée commerciale interne).
 */
enum Availability: string
{
    case Available = 'available';
    case LowStock = 'low_stock';
    case OutOfStock = 'out_of_stock';

    public static function fromVariant(ProductVariant $variant): self
    {
        return match (true) {
            !$variant->isInStock() => self::OutOfStock,
            $variant->isLowStock() => self::LowStock,
            default => self::Available,
        };
    }

    /**
     * Le meilleur état parmi les variantes actives : un produit est « disponible »
     * dès qu'une de ses variantes l'est, en rupture seulement si toutes sont épuisées.
     */
    public static function fromProduct(Product $product): self
    {
        $states = array_map(self::fromVariant(...), $product->getActiveVariants());

        foreach ([self::Available, self::LowStock] as $state) {
            if (in_array($state, $states, true)) {
                return $state;
            }
        }

        return self::OutOfStock;
    }
}
