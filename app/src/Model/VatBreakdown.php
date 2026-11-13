<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Décomposition d'un montant TTC en HT et TVA, en centimes.
 * Toujours vrai : totalExcludingTax + vatAmount === totalIncludingTax.
 */
final readonly class VatBreakdown
{
    public function __construct(
        public int $totalIncludingTax,
        public int $totalExcludingTax,
        public int $vatAmount,
        public int $vatRate,
    ) {
    }
}
