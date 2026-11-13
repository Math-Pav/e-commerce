<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
// Index composites du catalogue (ADR 0011) pour lire les produits actifs déjà dans l'ordre.
// Le tri par prix utilise le prix des variantes (ADR 0017) : il n'a plus d'index.
#[ORM\Index(name: 'idx_product_active_name', columns: ['is_active', 'name'])]
#[ORM\Index(name: 'idx_product_active_created_at', columns: ['is_active', 'created_at'])]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 150)]
    private ?string $name = null;

    #[ORM\Column(length: 170, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    // Prix de base en centimes (1299 = 12,99 €), utilisé par les variantes qui n'ont pas leur propre prix
    #[ORM\Column]
    private ?int $price = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    // Nom du fichier de la photo dans public/uploads/products/ (généré aléatoirement) ; null = visuel de remplacement
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $imageFilename = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    // Plusieurs produits pour une catégorie : la clé étrangère category_id est dans la table product
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Category $category = null;

    // Le stock est porté par les variantes (ADR 0017). Tout produit en a au moins une.
    /** @var Collection<int, ProductVariant> */
    #[ORM\OneToMany(targetEntity: ProductVariant::class, mappedBy: 'product', cascade: ['persist'])]
    #[ORM\OrderBy(['id' => 'ASC'])]
    private Collection $variants;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->variants = new ArrayCollection();
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

    public function setPrice(int $price): static
    {
        $this->price = $price;

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

    public function getImageFilename(): ?string
    {
        return $this->imageFilename;
    }

    public function setImageFilename(?string $imageFilename): static
    {
        $this->imageFilename = $imageFilename;

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

    public function setCategory(Category $category): static
    {
        $this->category = $category;

        return $this;
    }

    /**
     * @return Collection<int, ProductVariant>
     */
    public function getVariants(): Collection
    {
        return $this->variants;
    }

    public function addVariant(ProductVariant $variant): static
    {
        if (!$this->variants->contains($variant)) {
            $this->variants->add($variant);
            $variant->setProduct($this);
        }

        return $this;
    }

    /**
     * Les variantes proposées au client, dans l'ordre de création.
     *
     * @return list<ProductVariant>
     */
    public function getActiveVariants(): array
    {
        return array_values($this->variants->filter(
            static fn (ProductVariant $variant): bool => $variant->isActive(),
        )->toArray());
    }

    /**
     * La variante présélectionnée sur la fiche produit : la première variante active encore en stock.
     */
    public function getFirstVariantInStock(): ?ProductVariant
    {
        foreach ($this->getActiveVariants() as $variant) {
            if ($variant->isInStock()) {
                return $variant;
            }
        }

        return null;
    }

    /**
     * Le client choisit une variante seulement s'il y en a plusieurs ; sinon la variante par défaut est implicite.
     */
    public function hasVariantChoice(): bool
    {
        return count($this->getActiveVariants()) > 1;
    }

    /**
     * En stock si au moins une variante active l'est : « Rupture » seulement quand toutes sont épuisées.
     */
    public function isInStock(): bool
    {
        return null !== $this->getFirstVariantInStock();
    }

    /**
     * Stock total des variantes actives (affiché dans l'administration).
     */
    public function getTotalStock(): int
    {
        return array_sum(array_map(
            static fn (ProductVariant $variant): int => $variant->getStock(),
            $this->getActiveVariants(),
        ));
    }

    /**
     * Le plus petit prix des variantes actives : le « à partir de » du catalogue.
     * Sans variante active, le prix de base du produit.
     */
    public function getFromPrice(): int
    {
        $prices = $this->getActiveVariantPrices();

        return [] === $prices ? (int) $this->price : min($prices);
    }

    /**
     * Vrai si les variantes actives n'ont pas toutes le même prix (le catalogue affiche alors « à partir de »).
     */
    public function hasVariedPrices(): bool
    {
        return count(array_unique($this->getActiveVariantPrices())) > 1;
    }

    /**
     * @return list<int>
     */
    private function getActiveVariantPrices(): array
    {
        return array_map(
            static fn (ProductVariant $variant): int => $variant->getEffectivePrice(),
            $this->getActiveVariants(),
        );
    }
}
