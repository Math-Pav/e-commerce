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

    // Montant total en centimes, figé au moment de la commande
    #[ORM\Column]
    private ?int $totalAmount = null;

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

    public function setStatus(OrderStatus $status): static
    {
        $this->status = $status;

        return $this;
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

    public function setPaidAt(?\DateTimeImmutable $paidAt): static
    {
        $this->paidAt = $paidAt;

        return $this;
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
        $this->totalAmount = array_sum(array_map(
            static fn (OrderItem $item): int => $item->getSubtotal(),
            $this->items->toArray(),
        ));
    }
}
