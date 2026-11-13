<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Order>
 */
class OrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Order::class);
    }

    /**
     * Toutes les commandes avec leur client, les plus récentes en premier (pour l'administration).
     * La jointure charge les clients dans la même requête : pas de requête supplémentaire par ligne (N+1).
     *
     * @return list<Order>
     */
    public function findAllWithCustomer(?OrderStatus $status = null): array
    {
        $queryBuilder = $this->createQueryBuilder('o')
            ->addSelect('c')
            ->join('o.customer', 'c')
            ->orderBy('o.createdAt', 'DESC')
            ->addOrderBy('o.id', 'DESC');

        if ($status !== null) {
            $queryBuilder
                ->andWhere('o.status = :status')
                ->setParameter('status', $status);
        }

        return $queryBuilder->getQuery()->getResult();
    }

    /**
     * Une commande avec son client et ses lignes, chargés en une seule requête.
     */
    public function findOneWithDetails(string $reference): ?Order
    {
        return $this->createQueryBuilder('o')
            ->addSelect('c', 'i')
            ->join('o.customer', 'c')
            ->leftJoin('o.items', 'i')
            ->where('o.reference = :reference')
            ->setParameter('reference', $reference)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Verrouille la ligne de la commande jusqu'à la fin de la transaction et relit ses données à jour.
     * Doit être appelée à l'intérieur d'une transaction.
     */
    public function lockForUpdate(Order $order): void
    {
        $this->getEntityManager()->refresh($order, LockMode::PESSIMISTIC_WRITE);
    }
}
