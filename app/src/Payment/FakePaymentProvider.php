<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Order;

/**
 * Fournisseur de paiement simulé, pour le développement.
 * Il refuse les paiements au-delà d'un plafond fictif, pour pouvoir tester un refus à la main.
 */
final class FakePaymentProvider implements PaymentProviderInterface
{
    private const MAX_AMOUNT = 10000; // 100,00 €

    public function charge(Order $order): PaymentResult
    {
        if ($order->getTotalAmount() > self::MAX_AMOUNT) {
            return PaymentResult::failure('plafond de paiement dépassé (simulation au-delà de 100 €).');
        }

        return PaymentResult::success();
    }
}