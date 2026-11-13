<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Exception\InsufficientStockException;
use App\Model\Cart;
use App\Model\CartLine;
use App\Model\ShippingAddress;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class OrderServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private OrderService $orderService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->orderService = new OrderService(
            $this->entityManager,
            $container->get(ProductRepository::class),
            $container->get(OrderRepository::class),
        );
    }

    public function testPlaceOrder(): void
    {
        $mug = $this->findProduct('mug-noir-mat');          // 14,90 €, stock 12
        $pen = $this->findProduct('stylo-a-bille-bleu');    // 1,50 €, stock 200
        $cart = new Cart([new CartLine($mug, 2), new CartLine($pen, 3)]);

        $order = $this->orderService->placeOrder($this->findCustomer(), $cart, $this->createAddress());

        self::assertNotNull($order->getId(), 'La commande doit être enregistrée en base.');
        self::assertStringStartsWith('CMD-', (string) $order->getReference());
        self::assertSame(OrderStatus::Pending, $order->getStatus());
        self::assertCount(2, $order->getItems());
        // (2 × 14,90 €) + (3 × 1,50 €) = 29,80 € + 4,50 € = 34,30 €
        self::assertSame(3430, $order->getTotalAmount());
        self::assertSame(10, $mug->getStock());
        self::assertSame(197, $pen->getStock());
    }

    public function testOrderKeepsPriceAndNameAtTimeOfPurchase(): void
    {
        $mug = $this->findProduct('mug-noir-mat');
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($mug, 1)]),
            $this->createAddress(),
        );

        $mug->setPrice(9990)->setName('Mug noir mat (nouvelle édition)');
        $this->entityManager->flush();

        $item = $order->getItems()->first();
        self::assertSame(1490, $item->getUnitPrice());
        self::assertSame('Mug noir mat', $item->getProductName());
        self::assertSame(1490, $order->getTotalAmount());
    }

    public function testFailedOrderChangesNothing(): void
    {
        $orderCountBefore = $this->entityManager->getRepository(Order::class)->count([]);
        $cart = new Cart([
            new CartLine($this->findProduct('mug-noir-mat'), 2),
            new CartLine($this->findProduct('mug-isotherme'), 1), // stock à 0 : doit faire échouer la commande
        ]);

        try {
            $this->orderService->placeOrder($this->findCustomer(), $cart, $this->createAddress());
            self::fail('Une InsufficientStockException était attendue.');
        } catch (InsufficientStockException) {
        }

        // Après l'échec, on relit la base avec un EntityManager neuf
        $entityManager = static::getContainer()->get('doctrine')->resetManager();
        $mug = $entityManager->getRepository(Product::class)->findOneBy(['slug' => 'mug-noir-mat']);

        self::assertSame(12, $mug->getStock(), 'Le stock du premier produit doit être intact.');
        self::assertSame($orderCountBefore, $entityManager->getRepository(Order::class)->count([]), 'Aucune commande ne doit être créée.');
    }

    public function testCannotOrderEmptyCart(): void
    {
        $this->expectException(\DomainException::class);

        $this->orderService->placeOrder($this->findCustomer(), new Cart([]), $this->createAddress());
    }

    public function testCancelRestoresExactStock(): void
    {
        $mug = $this->findProduct('mug-noir-mat');          // stock 12
        $pen = $this->findProduct('stylo-a-bille-bleu');    // stock 200
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($mug, 2), new CartLine($pen, 3)]),
            $this->createAddress(),
        );

        $this->orderService->cancel($order);

        // On relit la base avec un EntityManager neuf : le changement doit être enregistré, pas seulement en mémoire
        $entityManager = static::getContainer()->get('doctrine')->resetManager();
        $savedOrder = $entityManager->getRepository(Order::class)->find($order->getId());

        self::assertSame(OrderStatus::Cancelled, $savedOrder->getStatus());
        self::assertNotNull($savedOrder->getCancelledAt());
        self::assertSame(12, $entityManager->getRepository(Product::class)->findOneBy(['slug' => 'mug-noir-mat'])->getStock());
        self::assertSame(200, $entityManager->getRepository(Product::class)->findOneBy(['slug' => 'stylo-a-bille-bleu'])->getStock());
    }

    public function testCancelIgnoresItemsWhoseProductWasDeleted(): void
    {
        $mug = $this->findProduct('mug-noir-mat');
        $pen = $this->findProduct('stylo-a-bille-bleu');
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($mug, 2), new CartLine($pen, 3)]),
            $this->createAddress(),
        );
        $orderId = $order->getId();

        // Suppression du stylo : la base met product_id à NULL dans la ligne de commande (onDelete: SET NULL)
        $this->entityManager->remove($pen);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        $this->orderService->cancel($order);

        self::assertSame(OrderStatus::Cancelled, $order->getStatus());
        self::assertSame(12, $this->findProduct('mug-noir-mat')->getStock());
    }

    public function testCancelChangesNothingWhenTransitionIsForbidden(): void
    {
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($this->findProduct('mug-noir-mat'), 2)]),
            $this->createAddress(),
        );
        $order->markAsPaid(new \DateTimeImmutable());
        $this->entityManager->flush();

        try {
            $this->orderService->cancel($order);
            self::fail('Une \DomainException était attendue : une commande payée ne peut pas être annulée.');
        } catch (\DomainException) {
        }

        $entityManager = static::getContainer()->get('doctrine')->resetManager();
        $savedOrder = $entityManager->getRepository(Order::class)->find($order->getId());

        self::assertSame(OrderStatus::Paid, $savedOrder->getStatus());
        self::assertNull($savedOrder->getCancelledAt());
        self::assertSame(10, $entityManager->getRepository(Product::class)->findOneBy(['slug' => 'mug-noir-mat'])->getStock(), 'Le stock ne doit pas être remis.');
    }

    public function testShipThenDeliverPaidOrder(): void
    {
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($this->findProduct('mug-noir-mat'), 1)]),
            $this->createAddress(),
        );
        $order->markAsPaid(new \DateTimeImmutable());

        $this->orderService->ship($order);
        self::assertSame(OrderStatus::Shipped, $order->getStatus());

        $this->orderService->deliver($order);
        self::assertSame(OrderStatus::Delivered, $order->getStatus());
    }

    private function findProduct(string $slug): Product
    {
        return $this->entityManager->getRepository(Product::class)->findOneBy(['slug' => $slug]);
    }

    private function findCustomer(): User
    {
        return $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'client@example.com']);
    }

    private function createAddress(): ShippingAddress
    {
        $address = new ShippingAddress();
        $address->fullName = 'Camille Martin';
        $address->address = '12 rue des Lilas';
        $address->postalCode = '69001';
        $address->city = 'Lyon';

        return $address;
    }
}