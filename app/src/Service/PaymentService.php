<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Exception\PaymentFailedException;
use App\Payment\PaymentProviderInterface;
use App\Payment\PaymentResult;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PaymentService
{
    private const NOT_PAYABLE_MESSAGE = 'Cette commande ne peut plus être payée.';

    public function __construct(
        private readonly PaymentProviderInterface $paymentProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerMailer $customerMailer,
        private readonly OrderRepository $orderRepository,
        private readonly InvoiceService $invoiceService,
    ) {
    }

    /**
     * Verrouille la commande, la débite, puis la marque comme payée et émet sa facture,
     * le tout dans une même transaction (voir docs/adr/0003-paiement-simule.md).
     *
     * @throws PaymentFailedException
     * @throws \DomainException       si la commande ne peut pas (ou plus) être payée
     */
    public function pay(Order $order): void
    {
        // Refus immédiat d'une commande déjà payée ou annulée : inutile d'ouvrir une transaction
        if (!$order->canBePaid()) {
            throw new \DomainException(self::NOT_PAYABLE_MESSAGE);
        }

        // Les refus « normaux » sont renvoyés par la transaction au lieu d'être levés dedans :
        // une exception levée dans wrapInTransaction() fermerait l'EntityManager.
        $outcome = $this->entityManager->wrapInTransaction(function () use ($order): Invoice|PaymentResult|null {
            // Verrou AVANT le débit : un second paiement ou une annulation simultanés attendent la fin
            // de cette transaction. La relecture révèle un statut changé entre-temps : on ne débite pas.
            $this->orderRepository->lockForUpdate($order);
            if (!$order->canBePaid()) {
                return null;
            }

            $result = $this->paymentProvider->charge($order);
            if (!$result->successful) {
                return $result;
            }

            // Paiement et facture dans la même transaction : une commande payée a toujours sa facture,
            // et un numéro de facture n'est jamais consommé sans facture enregistrée (pas de trou).
            $paidAt = new \DateTimeImmutable();
            $order->markAsPaid($paidAt);

            return $this->invoiceService->issue($order, $paidAt);
        });

        if (null === $outcome) {
            throw new \DomainException(self::NOT_PAYABLE_MESSAGE);
        }

        if ($outcome instanceof PaymentResult) {
            throw new PaymentFailedException((string) $outcome->errorMessage);
        }

        // Après la transaction : aucun e-mail n'annonce un paiement qui n'aurait pas été enregistré
        $this->customerMailer->sendPaymentConfirmation($order, $outcome);
    }
}
