<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\ApiTokenRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Jeton d'accès personnel à l'API (ADR 0021).
 * Le jeton en clair n'est jamais enregistré : seule son empreinte SHA-256 l'est, et le client
 * ne le voit qu'une fois, à la création. Révoquer un jeton supprime la ligne.
 */
#[ORM\Entity(repositoryClass: ApiTokenRepository::class)]
class ApiToken
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $customer;

    // Nom choisi par le client pour reconnaître le jeton (« Mon téléphone »)
    #[ORM\Column(length: 50)]
    private string $name;

    // Empreinte SHA-256 du jeton, en hexadécimal : c'est par elle qu'on retrouve le jeton
    #[ORM\Column(length: 64, unique: true)]
    private string $tokenHash;

    // Début du jeton en clair, affiché dans la liste pour que le client le reconnaisse
    #[ORM\Column(length: 8)]
    private string $tokenPrefix;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    public function __construct(User $customer, string $name, string $tokenHash, string $tokenPrefix, \DateTimeImmutable $expiresAt)
    {
        $this->customer = $customer;
        $this->name = $name;
        $this->tokenHash = $tokenHash;
        $this->tokenPrefix = $tokenPrefix;
        $this->expiresAt = $expiresAt;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCustomer(): User
    {
        return $this->customer;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getTokenHash(): string
    {
        return $this->tokenHash;
    }

    public function getTokenPrefix(): string
    {
        return $this->tokenPrefix;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        return $this->expiresAt <= $now;
    }

    public function markAsUsed(\DateTimeImmutable $usedAt): void
    {
        $this->lastUsedAt = $usedAt;
    }
}
