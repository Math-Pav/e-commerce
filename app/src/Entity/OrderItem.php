<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\OrderItemRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
class OrderItem
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Copie du nom et du prix au moment de l'achat : l'historique ne change pas si le produit change
    #[ORM\Column(length: 150)]
    private ?string $productName = null;

    #[ORM\Column]
    private ?int $unitPrice = null;

    #[ORM\Column]
    private ?int $quantity = null;

    #[ORM\ManyToOne(inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Order $order = null;

    // Facultatif : si le produit est supprimé du catalogue, la ligne est conservée et le lien devient NULL
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Product $product = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function setProductName(string $productName): static
    {
        $this->productName = $productName;

        return $this;
    }

    public function getUnitPrice(): ?int
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(int $unitPrice): static
    {
        $this->unitPrice = $unitPrice;

        return $this;
    }

    public function getQuantity(): ?int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;

        return $this;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(Order $order): static
    {
        $this->order = $order;

        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getSubtotal(): int
    {
        return $this->unitPrice * $this->quantity;
    }
}
