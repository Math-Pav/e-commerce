<?php

declare(strict_types=1);

namespace App\Api\Output;

/**
 * Une variante active d'un produit. label vaut null pour la variante par défaut d'un produit sans déclinaison.
 * La référence (SKU) est un identifiant commercial, pas l'identifiant technique en base.
 */
final class VariantOutput
{
    public function __construct(
        public readonly string $sku,
        public readonly ?string $label,
        public readonly Price $price,
        public readonly Availability $availability,
    ) {
    }
}
