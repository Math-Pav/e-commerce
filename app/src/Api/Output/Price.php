<?php

declare(strict_types=1);

namespace App\Api\Output;

/**
 * Un montant en centimes accompagné de sa devise : le client de l'API n'a pas à deviner l'unité.
 */
final class Price
{
    public const CURRENCY = 'EUR';

    public function __construct(
        public readonly int $amount,
        public readonly string $currency = self::CURRENCY,
    ) {
    }
}
