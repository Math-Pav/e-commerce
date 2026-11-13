<?php

declare(strict_types=1);

namespace App\Api\Output;

/**
 * La fiche d'un produit : les champs de la liste, la description et les variantes actives.
 */
final class ProductDetailOutput
{
    /**
     * @param list<VariantOutput> $variants
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly ?string $description,
        public readonly CategoryOutput $category,
        public readonly Price $price,
        public readonly bool $hasVariedPrices,
        public readonly Availability $availability,
        public readonly ?string $imageUrl,
        public readonly array $variants,
    ) {
    }
}
