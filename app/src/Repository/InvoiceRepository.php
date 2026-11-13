<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Invoice;
use App\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Invoice>
 */
class InvoiceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Invoice::class);
    }

    /**
     * La facture d'une commande avec ses lignes, chargées dans la même requête.
     */
    public function findOneByOrder(Order $order): ?Invoice
    {
        return $this->createQueryBuilder('i')
            ->addSelect('l')
            ->leftJoin('i.lines', 'l')
            ->where('i.order = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
