<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Exception\PaymentFailedException;
use App\Payment\PaymentProviderInterface;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;

final class PaymentService
{
    public function __construct(
        private readonly PaymentProviderInterface $paymentProvider,
        private readonly EntityManagerInterface $entityManager,
        private readonly CustomerMailer $customerMailer,
        private readonly OrderRepository $orderRepository,
        private readonly InvoiceService $invoiceService,
    ) {
    }

    /**
     * Débite la commande, puis la marque comme payée et émet sa facture dans une même transaction.
     *
     * @throws PaymentFailedException
     * @throws \DomainException       si la commande ne peut pas (ou plus) être payée
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

        // Paiement et facture dans une même transaction : une commande payée a toujours sa facture,
        // et un numéro de facture n'est jamais consommé sans facture enregistrée (pas de trou).
        $invoice = $this->entityManager->wrapInTransaction(function () use ($order): Invoice {
            // Verrou sur la commande : deux paiements simultanés ne la facturent pas deux fois.
            // La relecture peut révéler une commande payée entre-temps : markAsPaid() lève alors l'exception.
            $this->orderRepository->lockForUpdate($order);
            $paidAt = new \DateTimeImmutable();
            $order->markAsPaid($paidAt);

            return $this->invoiceService->issue($order, $paidAt);
        });

        // Après la transaction : aucun e-mail n'annonce un paiement qui n'aurait pas été enregistré
        $this->customerMailer->sendPaymentConfirmation($order, $invoice);
    }
}
