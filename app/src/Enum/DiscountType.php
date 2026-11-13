<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Type de remise d'un code promo. La valeur (à droite) est celle stockée en base.
 */
enum DiscountType: string
{
    case Percentage = 'percentage';
    case FixedAmount = 'fixed_amount';

    public function label(): string
    {
        return match ($this) {
            self::Percentage => 'Pourcentage',
            self::FixedAmount => 'Montant fixe',
        };
    }
}
