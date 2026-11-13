<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Repository\OrderItemRepository;
use App\Repository\ProductRepository;
use App\Service\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
final class DashboardController extends AbstractController
{
    private const BEST_SELLERS_LIMIT = 5;

    private const DAILY_REVENUE_DAYS = 14;

    #[Route('/admin', name: 'admin_dashboard', methods: ['GET'])]
    public function index(
        DashboardService $dashboardService,
        OrderItemRepository $orderItemRepository,
        ProductRepository $productRepository,
    ): Response {
        $now = new \DateTimeImmutable();

        return $this->render('admin/dashboard/index.html.twig', [
            'summary' => $dashboardService->getSalesSummary($now),
            'ordersByStatus' => $dashboardService->countOrdersByStatus(),
            'dailyRevenue' => $dashboardService->getDailyRevenue($now, self::DAILY_REVENUE_DAYS),
            'bestSellers' => $orderItemRepository->findBestSellingProducts(self::BEST_SELLERS_LIMIT),
            'lowStockProducts' => $productRepository->findOutOfStockOrLowStock(),
        ]);
    }
}
