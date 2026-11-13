<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ProductVariant;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductVariant>
 */
class ProductVariantRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductVariant::class);
    }

    /**
     * Les variantes en vente (variante et produit actifs) parmi ces identifiants, avec leur produit,
     * en une seule requête. Les identifiants inconnus ou indisponibles sont simplement absents du résultat.
     *
     * @param list<int> $ids
     *
     * @return list<ProductVariant>
     */
    public function findAvailableByIds(array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        return $this->createQueryBuilder('v')
            ->addSelect('p')
            ->join('v.product', 'p')
            ->where('v.id IN (:ids)')
            ->andWhere('v.isActive = true')
            ->andWhere('p.isActive = true')
            ->setParameter('ids', $ids)
            ->orderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Variantes en rupture ou en stock faible (voir ProductVariant::isInStock() et isLowStock()),
     * actives ou non, avec leur produit, les plus urgentes en premier.
     *
     * @return list<ProductVariant>
     */
    public function findOutOfStockOrLowStock(): array
    {
        return $this->createQueryBuilder('v')
            ->addSelect('p')
            ->join('v.product', 'p')
            ->where('v.stock <= :threshold')
            ->setParameter('threshold', ProductVariant::LOW_STOCK_THRESHOLD)
            ->orderBy('v.stock', 'ASC')
            ->addOrderBy('p.name', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Verrouille la ligne de la variante jusqu'à la fin de la transaction et relit ses données à jour.
     * Doit être appelée à l'intérieur d'une transaction.
     */
    public function lockForUpdate(ProductVariant $variant): void
    {
        $this->getEntityManager()->refresh($variant, LockMode::PESSIMISTIC_WRITE);
    }
}
