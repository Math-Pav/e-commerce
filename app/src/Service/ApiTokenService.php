<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Création, révocation et utilisation des jetons d'accès personnels à l'API (ADR 0021).
 */
final class ApiTokenService
{
    public const MAX_ACTIVE_TOKENS = 5;

    public const LIFETIME_DAYS = 90;

    // 32 octets aléatoires = 256 bits d'entropie : impossible à deviner, même par des milliards d'essais
    private const RANDOM_BYTES = 32;

    private const PREFIX_LENGTH = 8;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ApiTokenRepository $apiTokenRepository,
    ) {
    }

    /**
     * Crée un jeton et renvoie sa valeur en clair : c'est la seule fois qu'elle existe,
     * seule son empreinte est enregistrée.
     *
     * @throws \DomainException si le client a déjà le nombre maximal de jetons actifs
     */
    public function create(User $customer, string $name): string
    {
        $now = new \DateTimeImmutable();

        if ($this->apiTokenRepository->countActiveByCustomer($customer, $now) >= self::MAX_ACTIVE_TOKENS) {
            throw new \DomainException(sprintf('Vous avez déjà %d jetons actifs : révoquez-en un avant d\'en créer un nouveau.', self::MAX_ACTIVE_TOKENS));
        }

        $plainToken = bin2hex(random_bytes(self::RANDOM_BYTES));

        $this->entityManager->persist(new ApiToken(
            $customer,
            $name,
            self::hash($plainToken),
            substr($plainToken, 0, self::PREFIX_LENGTH),
            $now->modify(sprintf('+%d days', self::LIFETIME_DAYS)),
            $now,
        ));
        $this->entityManager->flush();

        return $plainToken;
    }

    /**
     * Révoquer = supprimer : le jeton devient inconnu et toute requête qui l'utilise reçoit une 401.
     */
    public function revoke(ApiToken $apiToken): void
    {
        $this->entityManager->remove($apiToken);
        $this->entityManager->flush();
    }

    public function findByPlainToken(string $plainToken): ?ApiToken
    {
        return $this->apiTokenRepository->findOneWithCustomerByHash(self::hash($plainToken));
    }

    public function markAsUsed(ApiToken $apiToken): void
    {
        $apiToken->markAsUsed(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * SHA-256 suffit ici, contrairement à un mot de passe : le jeton est aléatoire et long,
     * il n'existe pas de dictionnaire à essayer (ADR 0021).
     */
    public static function hash(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
