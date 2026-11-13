<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Exception\PaymentFailedException;
use App\Payment\PaymentProviderInterface;
use Doctrine\ORM\EntityManagerInterface;

final class PaymentService
{
    public function __construct(
        private readonly PaymentProviderInterface $paymentProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerMailer $customerMailer,
    ) {
    }

    /**
     * @throws PaymentFailedException
     */
    public function pay(Order $order): void
    {
        // Vérification AVANT d'appeler le fournisseur : on ne débite jamais deux fois
        if (!$order->canBePaid()) {
            throw new \DomainException('Cette commande ne peut plus être payée.');
        }

        $result = $this->paymentProvider->charge($order);

        if (!$result->successful) {
            throw new PaymentFailedException((string) $result->errorMessage);
        }

        $order->markAsPaid(new \DateTimeImmutable());
        $this->entityManager->flush();

        $this->customerMailer->sendPaymentConfirmation($order);
    }
}