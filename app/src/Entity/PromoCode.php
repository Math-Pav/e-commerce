<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\DiscountType;
use App\Exception\InvalidPromoCodeException;
use App\Repository\PromoCodeRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PromoCodeRepository::class)]
class PromoCode
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Toujours en majuscules (voir setCode()) : « bienvenue10 » et « BIENVENUE10 » sont le même code
    #[ORM\Column(length: 30, unique: true)]
    private ?string $code = null;

    #[ORM\Column(length: 20, enumType: DiscountType::class)]
    private DiscountType $discountType = DiscountType::Percentage;

    // Pourcentage entier (15 = 15 %) ou montant en centimes (500 = 5 €), selon le type
    #[ORM\Column]
    private int $value = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $startsAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endsAt = null;

    // Vide = utilisations illimitées
    #[ORM\Column(nullable: true)]
    private ?int $maxUsages = null;

    // Commandes non annulées qui ont utilisé ce code (voir OrderService)
    #[ORM\Column(options: ['default' => 0])]
    private int $usageCount = 0;

    // Montant minimal du panier en centimes ; vide = aucun minimum
    #[ORM\Column(nullable: true)]
    private ?int $minimumAmount = null;

    #[ORM\Column(options: ['default' => true])]
    private bool $isActive = true;

    public static function normalize(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = self::normalize($code);

        return $this;
    }

    public function getDiscountType(): DiscountType
    {
        return $this->discountType;
    }

    public function setDiscountType(DiscountType $discountType): static
    {
        $this->discountType = $discountType;

        return $this;
    }

    public function getValue(): int
    {
        return $this->value;
    }

    public function setValue(int $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getStartsAt(): ?\DateTimeImmutable
    {
        return $this->startsAt;
    }

    public function setStartsAt(?\DateTimeImmutable $startsAt): static
    {
        $this->startsAt = $startsAt;

        return $this;
    }

    public function getEndsAt(): ?\DateTimeImmutable
    {
        return $this->endsAt;
    }

    public function setEndsAt(?\DateTimeImmutable $endsAt): static
    {
        $this->endsAt = $endsAt;

        return $this;
    }

    public function getMaxUsages(): ?int
    {
        return $this->maxUsages;
    }

    public function setMaxUsages(?int $maxUsages): static
    {
        $this->maxUsages = $maxUsages;

        return $this;
    }

    public function getUsageCount(): int
    {
        return $this->usageCount;
    }

    public function getMinimumAmount(): ?int
    {
        return $this->minimumAmount;
    }

    public function setMinimumAmount(?int $minimumAmount): static
    {
        $this->minimumAmount = $minimumAmount;

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

    public function isMinimumReachedBy(int $subtotal): bool
    {
        return null === $this->minimumAmount || $subtotal >= $this->minimumAmount;
    }

    public function hasReachedMaxUsages(): bool
    {
        return null !== $this->maxUsages && $this->usageCount >= $this->maxUsages;
    }

    /**
     * Vérifie toutes les conditions d'utilisation du code pour un panier donné.
     * Appelée à l'application du code, à chaque affichage du panier et, sous verrou, à la commande.
     *
     * @throws InvalidPromoCodeException avec un message destiné au client
     */
    public function assertApplicable(\DateTimeImmutable $now, int $subtotal): void
    {
        $reason = match (true) {
            !$this->isActive => "n'est plus valable",
            null !== $this->startsAt && $now < $this->startsAt => "n'est pas encore valable",
            null !== $this->endsAt && $now > $this->endsAt => 'a expiré',
            $this->hasReachedMaxUsages() => "a atteint son nombre maximal d'utilisations",
            // Même format que le filtre Twig « price »
            !$this->isMinimumReachedBy($subtotal) => sprintf('demande un panier d\'au moins %s €', number_format((int) $this->minimumAmount / 100, 2, ',', ' ')),
            default => null,
        };

        if (null !== $reason) {
            throw new InvalidPromoCodeException(sprintf('Le code promo « %s » %s.', $this->code, $reason));
        }
    }

    /**
     * @throws InvalidPromoCodeException si le nombre maximal d'utilisations est déjà atteint
     */
    public function incrementUsage(): void
    {
        if ($this->hasReachedMaxUsages()) {
            throw new InvalidPromoCodeException(sprintf('Le code promo « %s » a atteint son nombre maximal d\'utilisations.', $this->code));
        }

        ++$this->usageCount;
    }

    /**
     * Rend une utilisation (commande annulée). Le compteur ne descend jamais sous zéro.
     */
    public function decrementUsage(): void
    {
        $this->usageCount = max(0, $this->usageCount - 1);
    }
}
