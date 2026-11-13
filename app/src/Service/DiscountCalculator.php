<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PromoCode;
use App\Enum\DiscountType;

/**
 * Calcule le montant d'une remise, en centimes. Aucune dépendance : testable unitairement sans base ni conteneur.
 *
 * Ne vérifie que ce qui dépend du montant (minimum du panier) ; les autres conditions
 * (actif, dates, utilisations) sont vérifiées par PromoCode::assertApplicable().
 */
final class DiscountCalculator
{
    public function calculate(PromoCode $promoCode, int $subtotal): int
    {
        if ($subtotal <= 0 || !$promoCode->isMinimumReachedBy($subtotal)) {
            return 0;
        }

        return match ($promoCode->getDiscountType()) {
            // intdiv() arrondit à l'entier inférieur (montants positifs) : la remise ne dépasse jamais le pourcentage annoncé
            DiscountType::Percentage => intdiv($subtotal * min($promoCode->getValue(), 100), 100),
            // Plafonnée au sous-total : le total ne devient jamais négatif
            DiscountType::FixedAmount => min($promoCode->getValue(), $subtotal),
        };
    }
}
