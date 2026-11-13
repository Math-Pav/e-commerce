<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Model\SellerDetails;
use App\Repository\InvoiceSequenceRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Émet la facture d'une commande payée (voir docs/adr/0018-factures.md).
 */
final class InvoiceService
{
    private readonly SellerDetails $seller;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly InvoiceSequenceRepository $sequenceRepository,
        private readonly VatCalculator $vatCalculator,
        #[Autowire('%app.invoice.vat_rate%')]
        private readonly int $vatRate,
        #[Autowire('%app.invoice.seller_name%')] string $sellerName,
        #[Autowire('%app.invoice.seller_address%')] string $sellerAddress,
        #[Autowire('%app.invoice.seller_postal_code%')] string $sellerPostalCode,
        #[Autowire('%app.invoice.seller_city%')] string $sellerCity,
        #[Autowire('%app.invoice.seller_siret%')] string $sellerSiret,
        #[Autowire('%app.invoice.seller_vat_number%')] string $sellerVatNumber,
    ) {
        $this->seller = new SellerDetails($sellerName, $sellerAddress, $sellerPostalCode, $sellerCity, $sellerSiret, $sellerVatNumber);
    }

    /**
     * Doit être appelée DANS la transaction qui marque la commande comme payée :
     * si la facture échoue, le paiement n'est pas enregistré, et inversement (aucun numéro perdu).
     *
     * @throws \DomainException si la commande n'est pas payée
     */
    public function issue(Order $order, \DateTimeImmutable $issuedAt): Invoice
    {
        $fiscalYear = (int) $issuedAt->format('Y');
        $number = sprintf('FA-%d-%06d', $fiscalYear, $this->sequenceRepository->nextNumber($fiscalYear));

        $invoice = Invoice::issue(
            $order,
            $number,
            $issuedAt,
            $this->seller,
            $this->vatCalculator->fromAmountIncludingTax((int) $order->getTotalAmount(), $this->vatRate),
        );
        $this->entityManager->persist($invoice);

        return $invoice;
    }
}
