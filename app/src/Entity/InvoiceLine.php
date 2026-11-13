<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

/**
 * Ligne de facture : copie d'une ligne de commande (nom, variante, prix TTC, quantité). Immuable.
 */
#[ORM\Entity(readOnly: true)]
class InvoiceLine
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'lines')]
    #[ORM\JoinColumn(nullable: false)]
    private Invoice $invoice;

    #[ORM\Column(length: 150)]
    private string $productName;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $variantLabel;

    // Prix unitaire TTC en centimes
    #[ORM\Column]
    private int $unitPrice;

    #[ORM\Column]
    private int $quantity;

    // Total TTC de la ligne en centimes
    #[ORM\Column]
    private int $totalAmount;

    public function __construct(Invoice $invoice, OrderItem $item)
    {
        $this->invoice = $invoice;
        $this->productName = (string) $item->getProductName();
        $this->variantLabel = $item->getVariantLabel();
        $this->unitPrice = (int) $item->getUnitPrice();
        $this->quantity = (int) $item->getQuantity();
        $this->totalAmount = $item->getSubtotal();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInvoice(): Invoice
    {
        return $this->invoice;
    }

    public function getProductName(): string
    {
        return $this->productName;
    }

    /**
     * « Mug émaillé – Bleu », comme OrderItem::getDisplayName().
     */
    public function getDisplayName(): string
    {
        return null === $this->variantLabel
            ? $this->productName
            : sprintf('%s – %s', $this->productName, $this->variantLabel);
    }

    public function getVariantLabel(): ?string
    {
        return $this->variantLabel;
    }

    public function getUnitPrice(): int
    {
        return $this->unitPrice;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function getTotalAmount(): int
    {
        return $this->totalAmount;
    }
}
