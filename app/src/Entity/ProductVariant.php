<?php

declare(strict_types=1);

namespace App\Entity;

use App\Exception\InsufficientStockException;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Déclinaison d'un produit (couleur, format…) : c'est elle qui porte le stock (ADR 0017).
 * Un produit sans déclinaison a une seule variante « par défaut », sans libellé, invisible pour le client.
 */
#[ORM\Entity(repositoryClass: ProductVariantRepository::class)]
class ProductVariant
{
    public const LOW_STOCK_THRESHOLD = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Plusieurs variantes pour un produit : la clé étrangère product_id est dans la table product_variant
    #[ORM\ManyToOne(inversedBy: 'variants')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Product $product = null;

    // « Bleu », « A5 »… ; null pour la variante par défaut d'un produit sans déclinaison
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $label = null;

    // Référence unique (SKU, « Stock Keeping Unit ») : identifie l'article dans le stock
    #[ORM\Column(length: 64, unique: true)]
    private ?string $sku = null;

    // Prix propre à la variante, en centimes ; null = prix du produit
    #[ORM\Column(nullable: true)]
    private ?int $price = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $stock = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    /**
     * Appelée par Product::addVariant(), qui tient aussi à jour la collection du produit.
     */
    public function setProduct(Product $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(string $sku): static
    {
        $this->sku = $sku;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): static
    {
        $this->price = $price;

        return $this;
    }

    /**
     * Prix réellement payé : celui de la variante, sinon celui du produit.
     */
    public function getEffectivePrice(): int
    {
        return $this->price
            ?? $this->product?->getPrice()
            ?? throw new \LogicException('Variante sans prix ni produit.');
    }

    public function getStock(): int
    {
        return $this->stock;
    }

    public function setStock(int $stock): static
    {
        $this->stock = $stock;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    /**
     * En vente : la variante et son produit sont actifs (le stock n'est pas vérifié ici).
     */
    public function isAvailable(): bool
    {
        return $this->isActive && true === $this->product?->isActive();
    }

    /**
     * Nom affiché au client : « Mug émaillé – Bleu », ou le nom du produit seul pour une variante par défaut.
     */
    public function getName(): string
    {
        $productName = (string) $this->product?->getName();

        return null === $this->label ? $productName : sprintf('%s – %s', $productName, $this->label);
    }

    public function isInStock(): bool
    {
        return $this->stock > 0;
    }

    /**
     * Stock faible : encore disponible, mais à réapprovisionner bientôt.
     */
    public function isLowStock(): bool
    {
        return $this->stock > 0 && $this->stock <= self::LOW_STOCK_THRESHOLD;
    }

    /**
     * @throws InsufficientStockException
     */
    public function decreaseStock(int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('La quantité doit être au moins égale à 1.');
        }

        if ($quantity > $this->stock) {
            throw new InsufficientStockException($this);
        }

        $this->stock -= $quantity;
    }

    /**
     * Remet en stock une quantité (par exemple à l'annulation d'une commande).
     */
    public function increaseStock(int $quantity): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('La quantité doit être au moins égale à 1.');
        }

        $this->stock += $quantity;
    }
}
