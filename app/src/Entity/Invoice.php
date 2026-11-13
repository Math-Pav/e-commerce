<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OrderStatus;
use App\Model\SellerDetails;
use App\Model\VatBreakdown;
use App\Repository\InvoiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Facture d'une commande payée (voir docs/adr/0018-factures.md).
 *
 * Document comptable immuable : elle copie tout ce qu'elle affiche (vendeur, client, lignes, montants)
 * pour ne jamais changer, même si la commande, le client ou la configuration changent ensuite.
 * - pas de setter : tout est fixé à la création par issue() ;
 * - readOnly : Doctrine n'enregistre jamais de modification sur une facture déjà en base.
 * Une facture ne se supprime ni ne se corrige : on l'annule par un avoir (hors périmètre).
 */
#[ORM\Entity(repositoryClass: InvoiceRepository::class, readOnly: true)]
class Invoice
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Ex. FA-2026-000001 : unique, chronologique et sans trou dans l'année
    #[ORM\Column(length: 20, unique: true)]
    private string $number;

    // Une commande a au plus une facture : la colonne order_id est unique
    #[ORM\OneToOne]
    #[ORM\JoinColumn(nullable: false)]
    private Order $order;

    #[ORM\Column]
    private \DateTimeImmutable $issuedAt;

    #[ORM\Column(length: 20)]
    private string $orderReference;

    #[ORM\Column(length: 100)]
    private string $sellerName;

    #[ORM\Column(length: 255)]
    private string $sellerAddress;

    #[ORM\Column(length: 10)]
    private string $sellerPostalCode;

    #[ORM\Column(length: 100)]
    private string $sellerCity;

    #[ORM\Column(length: 14)]
    private string $sellerSiret;

    #[ORM\Column(length: 20)]
    private string $sellerVatNumber;

    #[ORM\Column(length: 100)]
    private string $customerName;

    #[ORM\Column(length: 180)]
    private string $customerEmail;

    // Adresse de facturation : celle de livraison de la commande (le site n'en demande pas d'autre)
    #[ORM\Column(length: 255)]
    private string $billingAddress;

    #[ORM\Column(length: 10)]
    private string $billingPostalCode;

    #[ORM\Column(length: 100)]
    private string $billingCity;

    // Montants en centimes, TTC sauf indication contraire
    #[ORM\Column]
    private int $subtotalAmount;

    #[ORM\Column]
    private int $discountAmount;

    #[ORM\Column(length: 30, nullable: true)]
    private ?string $discountCode;

    #[ORM\Column]
    private int $totalExcludingTax;

    // Taux en pourcentage entier (20 pour 20 %)
    #[ORM\Column]
    private int $vatRate;

    #[ORM\Column]
    private int $vatAmount;

    #[ORM\Column]
    private int $totalIncludingTax;

    /**
     * @var Collection<int, InvoiceLine>
     */
    #[ORM\OneToMany(targetEntity: InvoiceLine::class, mappedBy: 'invoice', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $lines;

    /**
     * Privé : une facture ne se crée que par issue(), qui vérifie la commande.
     */
    private function __construct()
    {
        $this->lines = new ArrayCollection();
    }

    /**
     * @throws \DomainException si la commande n'est pas payée ou si les montants ne correspondent pas
     */
    public static function issue(
        Order $order,
        string $number,
        \DateTimeImmutable $issuedAt,
        SellerDetails $seller,
        VatBreakdown $vat,
    ): self {
        if (OrderStatus::Paid !== $order->getStatus()) {
            throw new \DomainException('Seule une commande payée peut être facturée.');
        }

        $customer = $order->getCustomer() ?? throw new \LogicException('Commande sans client.');

        if ($vat->totalIncludingTax !== $order->getTotalAmount()) {
            throw new \DomainException('Le total TTC de la facture doit être celui de la commande.');
        }

        $invoice = new self();
        $invoice->number = $number;
        $invoice->order = $order;
        $invoice->issuedAt = $issuedAt;
        $invoice->orderReference = (string) $order->getReference();

        $invoice->sellerName = $seller->name;
        $invoice->sellerAddress = $seller->address;
        $invoice->sellerPostalCode = $seller->postalCode;
        $invoice->sellerCity = $seller->city;
        $invoice->sellerSiret = $seller->siret;
        $invoice->sellerVatNumber = $seller->vatNumber;

        $invoice->customerName = sprintf('%s %s', $customer->getFirstName(), $customer->getLastName());
        $invoice->customerEmail = (string) $customer->getEmail();
        $invoice->billingAddress = (string) $order->getShippingAddress();
        $invoice->billingPostalCode = (string) $order->getShippingPostalCode();
        $invoice->billingCity = (string) $order->getShippingCity();

        $invoice->subtotalAmount = (int) $order->getSubtotalAmount();
        $invoice->discountAmount = $order->getDiscountAmount();
        $invoice->discountCode = $order->getDiscountCode();
        $invoice->totalExcludingTax = $vat->totalExcludingTax;
        $invoice->vatRate = $vat->vatRate;
        $invoice->vatAmount = $vat->vatAmount;
        $invoice->totalIncludingTax = $vat->totalIncludingTax;

        foreach ($order->getItems() as $item) {
            $invoice->lines->add(new InvoiceLine($invoice, $item));
        }

        return $invoice;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getNumber(): string
    {
        return $this->number;
    }

    public function getOrder(): Order
    {
        return $this->order;
    }

    public function getIssuedAt(): \DateTimeImmutable
    {
        return $this->issuedAt;
    }

    public function getOrderReference(): string
    {
        return $this->orderReference;
    }

    public function getSellerName(): string
    {
        return $this->sellerName;
    }

    public function getSellerAddress(): string
    {
        return $this->sellerAddress;
    }

    public function getSellerPostalCode(): string
    {
        return $this->sellerPostalCode;
    }

    public function getSellerCity(): string
    {
        return $this->sellerCity;
    }

    public function getSellerSiret(): string
    {
        return $this->sellerSiret;
    }

    public function getSellerVatNumber(): string
    {
        return $this->sellerVatNumber;
    }

    public function getCustomerName(): string
    {
        return $this->customerName;
    }

    public function getCustomerEmail(): string
    {
        return $this->customerEmail;
    }

    public function getBillingAddress(): string
    {
        return $this->billingAddress;
    }

    public function getBillingPostalCode(): string
    {
        return $this->billingPostalCode;
    }

    public function getBillingCity(): string
    {
        return $this->billingCity;
    }

    public function getSubtotalAmount(): int
    {
        return $this->subtotalAmount;
    }

    public function getDiscountAmount(): int
    {
        return $this->discountAmount;
    }

    public function getDiscountCode(): ?string
    {
        return $this->discountCode;
    }

    public function getTotalExcludingTax(): int
    {
        return $this->totalExcludingTax;
    }

    public function getVatRate(): int
    {
        return $this->vatRate;
    }

    public function getVatAmount(): int
    {
        return $this->vatAmount;
    }

    public function getTotalIncludingTax(): int
    {
        return $this->totalIncludingTax;
    }

    /**
     * @return Collection<int, InvoiceLine>
     */
    public function getLines(): Collection
    {
        return $this->lines;
    }

    public function getFilename(): string
    {
        return $this->number.'.pdf';
    }
}
