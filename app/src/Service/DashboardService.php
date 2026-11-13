<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\OrderStatus;
use App\Model\SalesSummary;
use App\Repository\OrderRepository;

/**
 * Statistiques de ventes du tableau de bord de l'administration.
 * Les calculs sont faits par la base (voir OrderRepository) ; ce service fixe les périodes
 * et met les résultats en forme.
 */
final class DashboardService
{
    // Fuseau des jours affichés : le même que celui des dates Twig (config/packages/twig.yaml)
    public const DISPLAY_TIMEZONE = 'Europe/Paris';

    public function __construct(private readonly OrderRepository $orderRepository)
    {
    }

    /**
     * Le chiffre d'affaires « des 30 derniers jours » couvre aujourd'hui et les 29 jours précédents,
     * à partir de minuit (heure de Paris) : les mêmes jours entiers que le graphique.
     */
    public function getSalesSummary(\DateTimeImmutable $now): SalesSummary
    {
        $allTime = $this->orderRepository->sumRevenue();
        $last30Days = $this->orderRepository->sumRevenue($this->startOfDay($now, 29));

        return new SalesSummary($allTime['revenue'], $allTime['orderCount'], $last30Days['revenue']);
    }

    /**
     * Nombre de commandes pour chaque statut, dans l'ordre de l'enum, y compris les statuts sans commande.
     *
     * @return list<array{status: OrderStatus, count: int}>
     */
    public function countOrdersByStatus(): array
    {
        $counts = $this->orderRepository->countByStatus();

        return array_map(
            static fn (OrderStatus $status): array => ['status' => $status, 'count' => $counts[$status->value] ?? 0],
            OrderStatus::cases(),
        );
    }

    /**
     * Chiffre d'affaires des $days derniers jours (aujourd'hui compris), du plus ancien au plus récent.
     * Les jours sans vente valent 0 : le graphique a toujours $days barres.
     *
     * @return list<array{day: \DateTimeImmutable, revenue: int}>
     */
    public function getDailyRevenue(\DateTimeImmutable $now, int $days = 14): array
    {
        $firstDay = $this->startOfDay($now, $days - 1);
        $revenueByDay = $this->orderRepository->sumRevenueByDay($firstDay, self::DISPLAY_TIMEZONE);

        $dailyRevenue = [];
        for ($i = 0; $i < $days; ++$i) {
            $day = $firstDay->modify(sprintf('+%d days', $i));
            $dailyRevenue[] = ['day' => $day, 'revenue' => $revenueByDay[$day->format('Y-m-d')] ?? 0];
        }

        return $dailyRevenue;
    }

    /**
     * Minuit (heure de Paris) du jour situé $daysAgo jours avant $now.
     */
    private function startOfDay(\DateTimeImmutable $now, int $daysAgo): \DateTimeImmutable
    {
        return $now
            ->setTimezone(new \DateTimeZone(self::DISPLAY_TIMEZONE))
            ->modify(sprintf('-%d days', $daysAgo))
            ->setTime(0, 0);
    }
}
