<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use App\Exception\InsufficientStockException;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
class Product
{
    public const LOW_STOCK_THRESHOLD = 5;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    #[Assert\NotBlank(message: 'Veuillez saisir le nom du produit.')]
    #[Assert\Length(max: 150)]
    private ?string $name = null;

    #[ORM\Column(length: 170, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    #[Assert\Length(max: 5000)]
    private ?string $description = null;

    // Prix en centimes : 1299 = 12,99 €
    #[ORM\Column]
    #[Assert\NotNull(message: 'Veuillez saisir le prix.')]
    #[Assert\PositiveOrZero(message: 'Le prix ne peut pas être négatif.')]
    private ?int $price = null;

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero(message: 'Le stock ne peut pas être négatif.')]
    private int $stock = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    // Plusieurs produits pour une catégorie : la clé étrangère category_id est dans la table product
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Veuillez choisir une catégorie.')]
    private ?Category $category = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    // Accepte null : un champ vidé dans le formulaire d'édition passe par ce setter avant la validation (NotNull)
    public function setPrice(?int $price): static
    {
        $this->price = $price;

        return $this;
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

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    // Accepte null pour la même raison que setPrice() : la contrainte NotNull renvoie l'erreur au formulaire
    public function setCategory(?Category $category): static
    {
        $this->category = $category;

        return $this;
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
}
