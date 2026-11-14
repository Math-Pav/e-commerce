<?php

declare(strict_types=1);

namespace App\Payment;

use App\Entity\Order;

/**
 * Fournisseur de paiement simulé (voir docs/adr/0003-paiement-simule.md).
 *
 * Il accepte tous les paiements, sauf une commande dont le total vaut exactement 9 999,99 € :
 * ce montant de test, sur le modèle des numéros de carte de test des vrais prestataires,
 * permet de reproduire un refus à la main sans jamais bloquer une commande normale.
 */
final class FakePaymentProvider implements PaymentProviderInterface
{
    public const DECLINED_AMOUNT = 999999; // 9 999,99 €

    public function charge(Order $order): PaymentResult
    {
        if (self::DECLINED_AMOUNT === $order->getTotalAmount()) {
            return PaymentResult::failure('refus simulé (montant de test de 9 999,99 €).');
        }

        return PaymentResult::success();
    }
}
