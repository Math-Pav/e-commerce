<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Order;
use App\Enum\OrderStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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
     * Chiffre d'affaires (en centimes) et nombre des commandes payées, expédiées ou livrées.
     * Avec $paidSince, seules les commandes payées à partir de cette date sont comptées.
     * Le calcul est fait par la base (SUM, COUNT) : aucune commande n'est chargée en mémoire.
     *
     * @return array{revenue: int, orderCount: int}
     */
    public function sumRevenue(?\DateTimeImmutable $paidSince = null): array
    {
        // COALESCE : sans aucune commande, SUM renvoie NULL ; on veut 0
        $queryBuilder = $this->createQueryBuilder('o')
            ->select('COALESCE(SUM(o.totalAmount), 0) AS revenue', 'COUNT(o.id) AS orderCount')
            ->where('o.status IN (:statuses)')
            ->setParameter('statuses', OrderStatus::revenueStatuses());

        if ($paidSince !== null) {
            $queryBuilder
                ->andWhere('o.paidAt >= :paidSince')
                ->setParameter('paidSince', self::toStorageTimezone($paidSince));
        }

        $result = $queryBuilder->getQuery()->getSingleResult();

        return [
            'revenue' => (int) $result['revenue'],
            'orderCount' => (int) $result['orderCount'],
        ];
    }

    /**
     * Nombre de commandes pour chaque statut présent en base (un statut sans commande est absent).
     *
     * @return array<string, int> valeur du statut => nombre de commandes
     */
    public function countByStatus(): array
    {
        $rows = $this->createQueryBuilder('o')
            ->select('o.status AS status', 'COUNT(o.id) AS orderCount')
            ->groupBy('o.status')
            ->getQuery()
            ->getArrayResult();

        // Doctrine reconvertit la colonne en enum OrderStatus, même dans un résultat de type tableau
        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status']->value] = (int) $row['orderCount'];
        }

        return $counts;
    }

    /**
     * Chiffre d'affaires jour par jour depuis $paidSince, les jours étant ceux du fuseau $timezone.
     * Un jour sans vente est absent du résultat.
     *
     * Requête SQL native : DQL ne connaît ni DATE() ni CONVERT_TZ(), il ne sait donc pas
     * regrouper par jour, encore moins par jour dans un autre fuseau que celui du stockage.
     * Les dates sont enregistrées dans le fuseau par défaut de PHP (UTC dans notre conteneur) :
     * CONVERT_TZ les ramène dans $timezone avant d'en garder le jour.
     * Les valeurs passent par des paramètres nommés, jamais par concaténation.
     *
     * @return array<string, int> jour au format Y-m-d => chiffre d'affaires en centimes
     */
    public function sumRevenueByDay(\DateTimeImmutable $paidSince, string $timezone): array
    {
        $sql = <<<'SQL'
            SELECT DATE(CONVERT_TZ(o.paid_at, :storageTimezone, :timezone)) AS sale_day,
                   SUM(o.total_amount) AS revenue
            FROM orders o
            WHERE o.status IN (:statuses)
              AND o.paid_at >= :paidSince
            GROUP BY sale_day
            ORDER BY sale_day
            SQL;

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            $sql,
            [
                'storageTimezone' => date_default_timezone_get(),
                'timezone' => $timezone,
                'statuses' => array_map(static fn (OrderStatus $status): string => $status->value, OrderStatus::revenueStatuses()),
                'paidSince' => self::toStorageTimezone($paidSince)->format('Y-m-d H:i:s'),
            ],
            ['statuses' => ArrayParameterType::STRING],
        );

        $revenueByDay = [];
        foreach ($rows as $row) {
            $revenueByDay[(string) $row['sale_day']] = (int) $row['revenue'];
        }

        return $revenueByDay;
    }

    /**
     * Verrouille la ligne de la commande jusqu'à la fin de la transaction et relit ses données à jour.
     * Doit être appelée à l'intérieur d'une transaction.
     */
    public function lockForUpdate(Order $order): void
    {
        $this->getEntityManager()->refresh($order, LockMode::PESSIMISTIC_WRITE);
    }

    /**
     * Doctrine enregistre une date telle quelle, sans tenir compte de son fuseau :
     * une date de comparaison doit donc être exprimée dans le fuseau de stockage (celui de PHP).
     */
    private static function toStorageTimezone(\DateTimeImmutable $date): \DateTimeImmutable
    {
        return $date->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }
}
