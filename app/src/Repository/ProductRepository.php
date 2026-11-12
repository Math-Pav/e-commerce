<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\LockMode;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

        /**
     * @return list<Product>
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c')
            ->join('p.category', 'c')
            ->where('p.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('p.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Verrouille la ligne du produit jusqu'à la fin de la transaction et relit ses données à jour.
     * Doit être appelée à l'intérieur d'une transaction.
     */
    public function lockForUpdate(Product $product): void
    {
        $this->getEntityManager()->refresh($product, LockMode::PESSIMISTIC_WRITE);
    }
}
