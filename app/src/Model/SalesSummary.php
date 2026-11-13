<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Indicateurs de ventes du tableau de bord. Tous les montants sont en centimes.
 * Seules les commandes payées, expédiées ou livrées sont comptées (OrderStatus::revenueStatuses()).
 */
final readonly class SalesSummary
{
    public function __construct(
        public int $totalRevenue,
        public int $orderCount,
        public int $revenueLast30Days,
    ) {
    }

    /**
     * Panier moyen arrondi au centime le plus proche, ou null s'il n'y a encore aucune commande.
     */
    public function getAverageOrderValue(): ?int
    {
        if (0 === $this->orderCount) {
            return null;
        }

        return (int) round($this->totalRevenue / $this->orderCount);
    }
}
