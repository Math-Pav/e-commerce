<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\VatBreakdown;

/**
 * Calcule le HT et la TVA à partir d'un montant TTC en centimes (les prix du site sont TTC).
 *
 * Règle d'arrondi (voir docs/adr/0018-factures.md) :
 * - HT = TTC × 100 / (100 + taux), arrondi au centime le plus proche, la moitié vers le haut ;
 * - TVA = TTC − HT : la TVA absorbe l'arrondi, et HT + TVA retombe toujours exactement sur le TTC payé.
 *
 * Le calcul est fait une seule fois, sur le total de la facture : arrondir chaque ligne puis additionner
 * pourrait donner un total HT différent de quelques centimes.
 * Tout est calculé en entiers : aucun float, donc aucune erreur de représentation.
 */
final class VatCalculator
{
    /**
     * @param int $vatRate taux de TVA en pourcentage entier (20 pour 20 %)
     */
    public function fromAmountIncludingTax(int $amountIncludingTax, int $vatRate): VatBreakdown
    {
        if ($amountIncludingTax < 0) {
            throw new \InvalidArgumentException('Le montant TTC ne peut pas être négatif.');
        }

        if ($vatRate < 0) {
            throw new \InvalidArgumentException('Le taux de TVA ne peut pas être négatif.');
        }

        $divisor = 100 + $vatRate;
        // Arrondi « moitié vers le haut » en entiers : floor(x + 1/2) = intdiv(2 × numérateur + diviseur, 2 × diviseur)
        $amountExcludingTax = intdiv(2 * 100 * $amountIncludingTax + $divisor, 2 * $divisor);

        return new VatBreakdown(
            $amountIncludingTax,
            $amountExcludingTax,
            $amountIncludingTax - $amountExcludingTax,
            $vatRate,
        );
    }
}
