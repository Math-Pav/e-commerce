<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

// "order" est un mot réservé en SQL (ORDER BY) : la table s'appelle "orders"
#[ORM\Entity(repositoryClass: OrderRepository::class)]
#[ORM\Table(name: 'orders')]
class Order
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20, unique: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 20, enumType: OrderStatus::class)]
    private OrderStatus $status = OrderStatus::Pending;

    // Somme des lignes en centimes, avant remise
    #[ORM\Column]
    private ?int $subtotalAmount = null;

    // Remise du code promo en centimes, figée au moment de la commande
    #[ORM\Column(options: ['default' => 0])]
    private int $discountAmount = 0;

    // Montant total en centimes (sous-total moins remise), figé au moment de la commande
    #[ORM\Column]
    private ?int $totalAmount = null;

    // Copie du code utilisé : la commande le garde même si le code est renommé ensuite
    #[ORM\Column(length: 30, nullable: true)]
    private ?string $discountCode = null;

    // Lien vers le code promo, pour rendre son utilisation si la commande est annulée
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(onDelete: 'SET NULL')]
    private ?PromoCode $promoCode = null;

    // Adresse de livraison copiée : elle ne doit pas changer si le client déménage
    #[ORM\Column(length: 100)]
    private ?string $shippingFullName = null;

    #[ORM\Column(length: 255)]
    private ?string $shippingAddress = null;

    #[ORM\Column(length: 10)]
    private ?string $shippingPostalCode = null;

    #[ORM\Column(length: 100)]
    private ?string $shippingCity = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $paidAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $shippedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $deliveredAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $cancelledAt = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $customer = null;

    /**
     * Relation bidirectionnelle : on a souvent besoin de parcourir les lignes d'une commande.
     * cascade persist : enregistrer la commande enregistre aussi ses lignes.
     * orphanRemoval : une ligne retirée de la commande est supprimée de la base.
     *
     * @var Collection<int, OrderItem>
     */
    #[ORM\OneToMany(targetEntity: OrderItem::class, mappedBy: 'order', cascade: ['persist'], orphanRemoval: true)]
    private Collection $items;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->items = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(string $reference): static
    {
        $this->reference = $reference;

        return $this;
    }

    public function getStatus(): OrderStatus
    {
        return $this->status;
    }

    public function getTotalAmount(): ?int
    {
        return $this->totalAmount;
    }

    public function setTotalAmount(int $totalAmount): static
    {
        $this->totalAmount = $totalAmount;

        return $this;
    }

    public function getSubtotalAmount(): ?int
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

    public function getPromoCode(): ?PromoCode
    {
        return $this->promoCode;
    }

    public function getShippingFullName(): ?string
    {
        return $this->shippingFullName;
    }

    public function setShippingFullName(string $shippingFullName): static
    {
        $this->shippingFullName = $shippingFullName;

        return $this;
    }

    public function getShippingAddress(): ?string
    {
        return $this->shippingAddress;
    }

    public function setShippingAddress(string $shippingAddress): static
    {
        $this->shippingAddress = $shippingAddress;

        return $this;
    }

    public function getShippingPostalCode(): ?string
    {
        return $this->shippingPostalCode;
    }

    public function setShippingPostalCode(string $shippingPostalCode): static
    {
        $this->shippingPostalCode = $shippingPostalCode;

        return $this;
    }

    public function getShippingCity(): ?string
    {
        return $this->shippingCity;
    }

    public function setShippingCity(string $shippingCity): static
    {
        $this->shippingCity = $shippingCity;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPaidAt(): ?\DateTimeImmutable
    {
        return $this->paidAt;
    }

    public function getShippedAt(): ?\DateTimeImmutable
    {
        return $this->shippedAt;
    }

    public function getDeliveredAt(): ?\DateTimeImmutable
    {
        return $this->deliveredAt;
    }

    public function getCancelledAt(): ?\DateTimeImmutable
    {
        return $this->cancelledAt;
    }

    public function getCustomer(): ?User
    {
        return $this->customer;
    }

    public function setCustomer(User $customer): static
    {
        $this->customer = $customer;

        return $this;
    }

    /**
     * @return Collection<int, OrderItem>
     */
    public function getItems(): Collection
    {
        return $this->items;
    }

    public function addItem(OrderItem $item): static
    {
        if (!$this->items->contains($item)) {
            $this->items->add($item);
            $item->setOrder($this);
        }

        return $this;
    }

    public function removeItem(OrderItem $item): static
    {
        $this->items->removeElement($item);

        return $this;
    }

    public function recalculateTotal(): void
    {
        $this->subtotalAmount = array_sum(array_map(
            static fn (OrderItem $item): int => $item->getSubtotal(),
            $this->items->toArray(),
        ));
        $this->totalAmount = $this->subtotalAmount - $this->discountAmount;
    }

    /**
     * Enregistre la remise et une copie du code. À appeler après recalculateTotal() (le sous-total doit être connu).
     */
    public function applyDiscount(PromoCode $promoCode, int $discountAmount): void
    {
        if ($discountAmount < 0 || $discountAmount > (int) $this->subtotalAmount) {
            throw new \DomainException('La remise doit être comprise entre zéro et le sous-total de la commande.');
        }

        $this->promoCode = $promoCode;
        $this->discountCode = $promoCode->getCode();
        $this->discountAmount = $discountAmount;
        $this->recalculateTotal();
    }

    public function canBePaid(): bool
    {
        return OrderStatus::Pending === $this->status;
    }

    public function markAsPaid(\DateTimeImmutable $paidAt): void
    {
        if (!$this->canBePaid()) {
            throw new \DomainException('Cette commande ne peut plus être payée.');
        }

        $this->status = OrderStatus::Paid;
        $this->paidAt = $paidAt;
    }

    public function canBeShipped(): bool
    {
        return OrderStatus::Paid === $this->status;
    }

    public function markAsShipped(\DateTimeImmutable $shippedAt): void
    {
        if (!$this->canBeShipped()) {
            throw new \DomainException('Seule une commande payée peut être expédiée.');
        }

        $this->status = OrderStatus::Shipped;
        $this->shippedAt = $shippedAt;
    }

    public function canBeDelivered(): bool
    {
        return OrderStatus::Shipped === $this->status;
    }

    public function markAsDelivered(\DateTimeImmutable $deliveredAt): void
    {
        if (!$this->canBeDelivered()) {
            throw new \DomainException('Seule une commande expédiée peut être livrée.');
        }

        $this->status = OrderStatus::Delivered;
        $this->deliveredAt = $deliveredAt;
    }

    /**
     * Seule une commande en attente de paiement peut être annulée :
     * annuler une commande payée demanderait un remboursement (voir ADR 0007).
     */
    public function canBeCancelled(): bool
    {
        return OrderStatus::Pending === $this->status;
    }

    public function cancel(\DateTimeImmutable $cancelledAt): void
    {
        if (!$this->canBeCancelled()) {
            throw new \DomainException('Seule une commande en attente de paiement peut être annulée.');
        }

        $this->status = OrderStatus::Cancelled;
        $this->cancelledAt = $cancelledAt;
    }
}
