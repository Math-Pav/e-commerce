<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ApiToken>
 */
class ApiTokenRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ApiToken::class);
    }

    /**
     * Le jeton et son client en une requête : l'authentification n'a pas besoin d'en faire une seconde.
     */
    public function findOneWithCustomerByHash(string $tokenHash): ?ApiToken
    {
        return $this->createQueryBuilder('t')
            ->addSelect('c')
            ->join('t.customer', 'c')
            ->where('t.tokenHash = :tokenHash')
            ->setParameter('tokenHash', $tokenHash)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @return list<ApiToken>
     */
    public function findByCustomer(User $customer): array
    {
        return $this->findBy(['customer' => $customer], ['createdAt' => 'DESC', 'id' => 'DESC']);
    }

    public function countActiveByCustomer(User $customer, \DateTimeImmutable $now): int
    {
        return (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.customer = :customer')
            ->andWhere('t.expiresAt > :now')
            ->setParameter('customer', $customer)
            ->setParameter('now', $now)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
