<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Order;

/**
 * Contrat commun à tous les fournisseurs de paiement (simulé, Stripe, PayPal…).
 */
interface PaymentProviderInterface
{
    public function charge(Order $order): PaymentResult;
}
