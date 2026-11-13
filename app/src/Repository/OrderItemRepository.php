<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\OrderItem;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<OrderItem>
 */
class OrderItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrderItem::class);
    }

    /**
     * Les produits les plus vendus en quantité, d'après les lignes des commandes payées, expédiées ou livrées.
     * La jointure (interne) sur le produit écarte les lignes dont le produit a été supprimé.
     *
     * @return list<array{id: int, name: string, quantitySold: int}>
     */
    public function findBestSellingProducts(int $limit): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('p.id AS id', 'p.name AS name', 'SUM(i.quantity) AS quantitySold')
            ->join('i.order', 'o')
            ->join('i.product', 'p')
            ->where('o.status IN (:statuses)')
            ->setParameter('statuses', OrderStatus::revenueStatuses())
            ->groupBy('p.id', 'p.name')
            ->orderBy('quantitySold', 'DESC')
            ->addOrderBy('p.name', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'quantitySold' => (int) $row['quantitySold'],
        ], $rows);
    }
}
