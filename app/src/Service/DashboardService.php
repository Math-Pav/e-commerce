<?php

declare(strict_types=1);

namespace App\Service;

use App\Enum\OrderStatus;
use App\Model\SalesSummary;
use App\Repository\OrderItemRepository;
use App\Repository\OrderRepository;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Statistiques de ventes du tableau de bord de l'administration.
 * Les calculs sont faits par la base (voir OrderRepository) ; ce service fixe les périodes
 * et met les résultats en forme.
 *
 * Les résultats sont mis en cache avec l'étiquette CACHE_TAG, invalidée dès qu'une commande, une ligne
 * de commande ou un produit est enregistré (CacheInvalidationListener, ADR 0019).
 * Les chiffres liés à « aujourd'hui » ont la date du jour dans leur clé et expirent à minuit.
 */
final class DashboardService
{
    // Fuseau des jours affichés : le même que celui des dates Twig (config/packages/twig.yaml)
    public const DISPLAY_TIMEZONE = 'Europe/Paris';

    public const CACHE_TAG = 'dashboard';

    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly OrderItemRepository $orderItemRepository,
        private readonly TagAwareCacheInterface $computedCache,
    ) {
    }

    /**
     * Le chiffre d'affaires « des 30 derniers jours » couvre aujourd'hui et les 29 jours précédents,
     * à partir de minuit (heure de Paris) : les mêmes jours entiers que le graphique.
     */
    public function getSalesSummary(\DateTimeImmutable $now): SalesSummary
    {
        return $this->remember('sales_summary', $now, function () use ($now): SalesSummary {
            $allTime = $this->orderRepository->sumRevenue();
            $last30Days = $this->orderRepository->sumRevenue($this->startOfDay($now, 29));

            return new SalesSummary($allTime['revenue'], $allTime['orderCount'], $last30Days['revenue']);
        });
    }

    /**
     * Nombre de commandes pour chaque statut, dans l'ordre de l'enum, y compris les statuts sans commande.
     *
     * @return list<array{status: OrderStatus, count: int}>
     */
    public function countOrdersByStatus(): array
    {
        $counts = $this->remember('orders_by_status', null, fn (): array => $this->orderRepository->countByStatus());

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
        $revenueByDay = $this->remember(
            'daily_revenue_'.$days,
            $now,
            fn (): array => $this->orderRepository->sumRevenueByDay($firstDay, self::DISPLAY_TIMEZONE),
        );

        $dailyRevenue = [];
        for ($i = 0; $i < $days; ++$i) {
            $day = $firstDay->modify(sprintf('+%d days', $i));
            $dailyRevenue[] = ['day' => $day, 'revenue' => $revenueByDay[$day->format('Y-m-d')] ?? 0];
        }

        return $dailyRevenue;
    }

    /**
     * Produits les plus vendus (commandes payées, expédiées ou livrées).
     *
     * @return list<array{id: int, name: string, quantitySold: int}>
     */
    public function getBestSellingProducts(int $limit): array
    {
        return $this->remember(
            'best_sellers_'.$limit,
            null,
            fn (): array => $this->orderItemRepository->findBestSellingProducts($limit),
        );
    }

    /**
     * Lit une valeur dans le cache, ou la calcule avec $compute et l'y enregistre avec l'étiquette du tableau de bord.
     * Avec $now, la valeur dépend du jour : la date (heure de Paris) entre dans la clé et l'entrée expire à minuit,
     * pour que « les 30 derniers jours » ne restent jamais ceux d'hier.
     *
     * @template T
     *
     * @param callable(): T $compute
     *
     * @return T
     */
    private function remember(string $name, ?\DateTimeImmutable $now, callable $compute): mixed
    {
        $key = 'dashboard.'.$name;
        if (null !== $now) {
            $key .= '.'.$this->startOfDay($now, 0)->format('Y-m-d');
        }

        return $this->computedCache->get($key, function (ItemInterface $item) use ($now, $compute): mixed {
            $item->tag(self::CACHE_TAG);
            if (null !== $now) {
                $item->expiresAt($this->startOfDay($now, 0)->modify('+1 day'));
            }

            return $compute();
        });
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
