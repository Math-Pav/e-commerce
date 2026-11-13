<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Levée quand le fournisseur de paiement refuse le paiement.
 */
final class PaymentFailedException extends \RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct(sprintf('Le paiement a été refusé : %s', $reason));
    }
}