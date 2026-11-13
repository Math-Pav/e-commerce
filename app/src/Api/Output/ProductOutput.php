<?php

declare(strict_types=1);

namespace App\Api\Output;

/**
 * Un produit dans la liste du catalogue.
 * price : le plus petit prix des variantes actives ; hasVariedPrices indique un prix « à partir de ».
 */
final class ProductOutput
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly CategoryOutput $category,
        public readonly Price $price,
        public readonly bool $hasVariedPrices,
        public readonly Availability $availability,
        public readonly ?string $imageUrl,
    ) {
    }
}
