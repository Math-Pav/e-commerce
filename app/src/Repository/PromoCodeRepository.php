<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PromoCode;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PromoCode>
 */
class PromoCodeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PromoCode::class);
    }

    /**
     * Le code est enregistré en majuscules : la saisie du client est normalisée de la même façon.
     */
    public function findOneByCode(string $code): ?PromoCode
    {
        return $this->findOneBy(['code' => PromoCode::normalize($code)]);
    }

    /**
     * Verrouille la ligne du code jusqu'à la fin de la transaction et relit ses données à jour
     * (même principe que ProductRepository::lockForUpdate()). Doit être appelée dans une transaction.
     */
    public function lockForUpdate(PromoCode $promoCode): void
    {
        $this->getEntityManager()->refresh($promoCode, LockMode::PESSIMISTIC_WRITE);
    }
}
