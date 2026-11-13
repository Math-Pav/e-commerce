<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Repository\OrderItemRepository;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Service\DashboardService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\NullAdapter;
use Symfony\Component\Cache\Adapter\TagAwareAdapter;

/**
 * Test d'intégration : les commandes sont créées dans la base de test, puis chaque indicateur
 * est vérifié au centime près. Tout est annulé à la fin du test (DAMA DoctrineTestBundle).
 */
final class DashboardServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private DashboardService $dashboardService;

    private int $orderNumber = 0;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        // Sans cache (NullAdapter) : ces tests portent sur les calculs ; le cache est testé dans DashboardControllerTest
        $this->dashboardService = new DashboardService(
            $container->get(OrderRepository::class),
            $container->get(OrderItemRepository::class),
            new TagAwareAdapter(new NullAdapter()),
        );

        // On part d'une base sans commande pour connaître exactement les montants attendus
        $this->entityManager->createQuery('DELETE FROM App\Entity\OrderItem')->execute();
        $this->entityManager->createQuery('DELETE FROM App\Entity\Order')->execute();
    }

    public function testSalesSummary(): void
    {
        $this->createKnownOrders();

        $summary = $this->dashboardService->getSalesSummary($this->now());

        // A 29,80 € + B 15,00 € + C 9,90 € + D 34,90 € + G 14,90 € = 104,50 € (E en attente et F annulée exclues)
        self::assertSame(10450, $summary->totalRevenue);
        self::assertSame(5, $summary->orderCount);
        // 104,50 € / 5 = 20,90 €
        self::assertSame(2090, $summary->getAverageOrderValue());
        // Depuis le 2 mars 00:00 (Paris) : A + B + D = 79,70 € ; C (1er mars 23:30) et G (décembre) sont trop anciennes
        self::assertSame(7970, $summary->revenueLast30Days);
    }

    public function testOrdersByStatus(): void
    {
        $this->createKnownOrders();

        $counts = [];
        foreach ($this->dashboardService->countOrdersByStatus() as $row) {
            $counts[$row['status']->value] = $row['count'];
        }

        self::assertSame(
            ['pending' => 1, 'paid' => 3, 'shipped' => 1, 'delivered' => 1, 'cancelled' => 1],
            $counts,
        );
    }

    public function testDailyRevenueUsesParisDays(): void
    {
        $this->createKnownOrders();

        $dailyRevenue = $this->dashboardService->getDailyRevenue($this->now());

        self::assertCount(14, $dailyRevenue);
        self::assertSame('2026-03-18', $dailyRevenue[0]['day']->format('Y-m-d'));
        self::assertSame('2026-03-31', $dailyRevenue[13]['day']->format('Y-m-d'));

        $revenueByDay = [];
        foreach ($dailyRevenue as $row) {
            $revenueByDay[$row['day']->format('Y-m-d')] = $row['revenue'];
        }

        // B a été payée le 29 mars à 22:30 UTC, soit le 30 mars à 00:30 à Paris (heure d'été) : elle compte le 30
        self::assertSame(0, $revenueByDay['2026-03-29']);
        self::assertSame(1500, $revenueByDay['2026-03-30']);
        self::assertSame(2980, $revenueByDay['2026-03-31']);
        self::assertSame(4480, array_sum($revenueByDay), 'Les autres jours doivent valoir 0.');
    }

    public function testBestSellingProductsCountOnlyPaidOrders(): void
    {
        $this->createKnownOrders();
        $orderItemRepository = static::getContainer()->get(OrderItemRepository::class);

        $bestSellers = $orderItemRepository->findBestSellingProducts(5);

        // Le mug noir mat en attente (×100) et les stylos de la commande annulée (×100) ne comptent pas
        self::assertSame(
            ['Stylo à bille bleu' => 10, 'Mug noir mat' => 3, 'Carnet A5 ligné' => 1, 'Stylo plume' => 1],
            array_column($bestSellers, 'quantitySold', 'name'),
        );
        self::assertCount(2, $orderItemRepository->findBestSellingProducts(2));
    }

    public function testEmptyDatabase(): void
    {
        $summary = $this->dashboardService->getSalesSummary($this->now());

        self::assertSame(0, $summary->totalRevenue);
        self::assertSame(0, $summary->revenueLast30Days);
        self::assertSame(0, $summary->orderCount);
        self::assertNull($summary->getAverageOrderValue());
        self::assertSame([0, 0, 0, 0, 0], array_column($this->dashboardService->countOrdersByStatus(), 'count'));
        self::assertSame(array_fill(0, 14, 0), array_column($this->dashboardService->getDailyRevenue($this->now()), 'revenue'));
        self::assertSame([], static::getContainer()->get(OrderItemRepository::class)->findBestSellingProducts(5));
    }

    /**
     * Jeu de commandes connu. « Maintenant » = mardi 31 mars 2026 à 10:00, heure de Paris.
     */
    private function createKnownOrders(): void
    {
        // A : livrée, payée aujourd'hui → 2 × 14,90 € = 29,80 €
        $this->createOrder(OrderStatus::Delivered, '2026-03-31 09:00', ['mug-noir-mat' => 2]);
        // B : expédiée, payée juste après minuit (Paris) → 10 × 1,50 € = 15,00 €
        $this->createOrder(OrderStatus::Shipped, '2026-03-30 00:30', ['stylo-a-bille-bleu' => 10]);
        // C : payée juste avant le début de la période de 30 jours → 9,90 €
        $this->createOrder(OrderStatus::Paid, '2026-03-01 23:30', ['carnet-a5-ligne' => 1]);
        // D : payée juste après le début de la période de 30 jours → 34,90 €
        $this->createOrder(OrderStatus::Paid, '2026-03-02 00:15', ['stylo-plume' => 1]);
        // G : payée il y a longtemps → 14,90 €
        $this->createOrder(OrderStatus::Paid, '2025-12-01 12:00', ['mug-noir-mat' => 1]);
        // E : en attente de paiement → 1 490,00 €, exclue
        $this->createOrder(OrderStatus::Pending, null, ['mug-noir-mat' => 100]);
        // F : annulée → 150,00 €, exclue
        $this->createOrder(OrderStatus::Cancelled, null, ['stylo-a-bille-bleu' => 100]);

        $this->entityManager->flush();
    }

    /**
     * @param array<string, int> $quantities slug du produit => quantité
     */
    private function createOrder(OrderStatus $status, ?string $parisPaidAt, array $quantities): void
    {
        $order = (new Order())
            ->setReference(sprintf('TEST-%05d', ++$this->orderNumber))
            ->setCustomer($this->findCustomer())
            ->setShippingFullName('Camille Martin')
            ->setShippingAddress('12 rue des Lilas')
            ->setShippingPostalCode('69001')
            ->setShippingCity('Lyon');

        foreach ($quantities as $slug => $quantity) {
            $product = static::getContainer()->get(ProductRepository::class)->findOneBy(['slug' => $slug]);
            self::assertNotNull($product);
            $order->addItem((new OrderItem())
                ->setProduct($product)
                ->setProductName((string) $product->getName())
                ->setUnitPrice((int) $product->getPrice())
                ->setQuantity($quantity));
        }
        $order->recalculateTotal();

        if (null !== $parisPaidAt) {
            $paidAt = $this->parisTimeToStorage($parisPaidAt);
            $order->markAsPaid($paidAt);
            if (OrderStatus::Shipped === $status || OrderStatus::Delivered === $status) {
                $order->markAsShipped($paidAt);
            }
            if (OrderStatus::Delivered === $status) {
                $order->markAsDelivered($paidAt);
            }
        }
        if (OrderStatus::Cancelled === $status) {
            $order->cancel(new \DateTimeImmutable());
        }

        self::assertSame($status, $order->getStatus());
        $this->entityManager->persist($order);
    }

    private function now(): \DateTimeImmutable
    {
        return $this->parisTimeToStorage('2026-03-31 10:00');
    }

    /**
     * Une heure de Paris, exprimée dans le fuseau de stockage (celui de PHP), comme le ferait l'application.
     */
    private function parisTimeToStorage(string $parisTime): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($parisTime, new \DateTimeZone('Europe/Paris')))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    private function findCustomer(): User
    {
        $customer = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'client@example.com']);
        self::assertNotNull($customer);

        return $customer;
    }
}
