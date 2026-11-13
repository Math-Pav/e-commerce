<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Coordonnées du vendeur imprimées sur les factures, lues dans la configuration (config/services.yaml).
 */
final readonly class SellerDetails
{
    public function __construct(
        public string $name,
        public string $address,
        public string $postalCode,
        public string $city,
        public string $siret,
        public string $vatNumber,
    ) {
    }
}
