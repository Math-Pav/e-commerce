<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\ProductVariant;
use App\Entity\PromoCode;
use App\Entity\User;
use App\Enum\DiscountType;
use App\Enum\OrderStatus;
use App\Exception\InsufficientStockException;
use App\Exception\InvalidPromoCodeException;
use App\Model\Cart;
use App\Model\CartLine;
use App\Model\ShippingAddress;
use App\Repository\OrderRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\PromoCodeRepository;
use App\Service\CustomerMailer;
use App\Service\DiscountCalculator;
use App\Service\OrderService;
use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\ConnectionLost;
use Doctrine\ORM\Decorator\EntityManagerDecorator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
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
            $container->get(ProductVariantRepository::class),
            $container->get(OrderRepository::class),
            $container->get(PromoCodeRepository::class),
            new DiscountCalculator(),
            $container->get(CustomerMailer::class),
            new NullLogger(),
        );
    }

    public function testPlaceOrder(): void
    {
        $mug = $this->findVariant('MUG-NOIR-MAT');          // 14,90 €, stock 12
        $pen = $this->findVariant('STYLO-A-BILLE-BLEU');    // 1,50 €, stock 200
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
        self::assertEmailCount(1, message: 'La commande doit être confirmée par e-mail.');
    }

    public function testOrderKeepsPriceAndNameAtTimeOfPurchase(): void
    {
        $mug = $this->findVariant('MUG-NOIR-MAT');
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($mug, 1)]),
            $this->createAddress(),
        );

        $mug->getProduct()?->setPrice(9990)->setName('Mug noir mat (nouvelle édition)');
        $this->entityManager->flush();

        $item = $order->getItems()->first();
        self::assertInstanceOf(OrderItem::class, $item);
        self::assertSame(1490, $item->getUnitPrice());
        self::assertSame('Mug noir mat', $item->getProductName());
        self::assertSame(1490, $order->getTotalAmount());
    }

    public function testOrderCopiesVariantLabelAndPrice(): void
    {
        $blue = $this->findVariant('MUG-EMAILLE-BLEU');    // prix du produit : 16,90 €, stock 10
        $green = $this->findVariant('MUG-EMAILLE-VERT');   // prix propre : 18,90 €, stock 3
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($blue, 2), new CartLine($green, 1)]),
            $this->createAddress(),
        );

        $green->setLabel('Vert sapin')->setPrice(2490);
        $this->entityManager->flush();

        [$blueItem, $greenItem] = $order->getItems()->getValues();
        self::assertSame('Bleu', $blueItem->getVariantLabel());
        self::assertSame('Mug émaillé – Bleu', $blueItem->getDisplayName());
        self::assertSame(1690, $blueItem->getUnitPrice());
        self::assertSame($green, $greenItem->getVariant());
        self::assertSame('Vert', $greenItem->getVariantLabel(), 'Le libellé copié ne change pas avec la variante.');
        self::assertSame(1890, $greenItem->getUnitPrice(), 'Le prix propre de la variante est copié.');
        // (2 × 16,90 €) + 18,90 € = 52,70 €
        self::assertSame(5270, $order->getTotalAmount());
        self::assertSame(8, $blue->getStock());
        self::assertSame(2, $green->getStock());
    }

    public function testDefaultVariantHasNoLabelInOrder(): void
    {
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($this->findVariant('MUG-NOIR-MAT'), 1)]),
            $this->createAddress(),
        );

        $item = $order->getItems()->first();
        self::assertInstanceOf(OrderItem::class, $item);
        self::assertNull($item->getVariantLabel());
        self::assertSame('Mug noir mat', $item->getDisplayName());
    }

    public function testSoldOutVariantFailsOrderWithoutTouchingOtherVariant(): void
    {
        $cart = new Cart([
            new CartLine($this->findVariant('MUG-EMAILLE-BLEU'), 1),
            new CartLine($this->findVariant('MUG-EMAILLE-ROUGE'), 1), // stock à 0
        ]);

        try {
            $this->orderService->placeOrder($this->findCustomer(), $cart, $this->createAddress());
            self::fail('Une InsufficientStockException était attendue.');
        } catch (InsufficientStockException $exception) {
            self::assertSame('MUG-EMAILLE-ROUGE', $exception->variant->getSku());
        }

        $entityManager = static::getContainer()->get('doctrine')->resetManager();
        $blue = $entityManager->getRepository(ProductVariant::class)->findOneBy(['sku' => 'MUG-EMAILLE-BLEU']);
        self::assertNotNull($blue);
        self::assertSame(10, $blue->getStock());
    }

    public function testCancelRestoresStockOfEachVariant(): void
    {
        $blue = $this->findVariant('MUG-EMAILLE-BLEU');
        $green = $this->findVariant('MUG-EMAILLE-VERT');
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($blue, 4), new CartLine($green, 3)]),
            $this->createAddress(),
        );

        $this->orderService->cancel($order);

        self::assertSame(10, $blue->getStock());
        self::assertSame(3, $green->getStock());
    }

    public function testPromoCodeAppliesToVariantPrices(): void
    {
        $promoCode = $this->createPromoCode(null);
        // 18,90 € (prix propre de la variante verte) − 10 % = 1,89 € de remise
        $cart = new Cart([new CartLine($this->findVariant('MUG-EMAILLE-VERT'), 1)], $promoCode, 189);

        $order = $this->orderService->placeOrder($this->findCustomer(), $cart, $this->createAddress());

        self::assertSame(1890, $order->getSubtotalAmount());
        self::assertSame(189, $order->getDiscountAmount());
        self::assertSame(1701, $order->getTotalAmount());
    }

    public function testFailedOrderChangesNothing(): void
    {
        $orderCountBefore = $this->entityManager->getRepository(Order::class)->count([]);
        $cart = new Cart([
            new CartLine($this->findVariant('MUG-NOIR-MAT'), 2),
            new CartLine($this->findVariant('MUG-ISOTHERME'), 1), // stock à 0 : doit faire échouer la commande
        ]);

        try {
            $this->orderService->placeOrder($this->findCustomer(), $cart, $this->createAddress());
            self::fail('Une InsufficientStockException était attendue.');
        } catch (InsufficientStockException) {
        }

        // Après l'échec, on relit la base avec un EntityManager neuf
        $entityManager = static::getContainer()->get('doctrine')->resetManager();
        $mug = $entityManager->getRepository(ProductVariant::class)->findOneBy(['sku' => 'MUG-NOIR-MAT']);
        self::assertNotNull($mug);

        self::assertSame(12, $mug->getStock(), 'Le stock du premier produit doit être intact.');
        self::assertSame($orderCountBefore, $entityManager->getRepository(Order::class)->count([]), 'Aucune commande ne doit être créée.');
        self::assertEmailCount(0, message: "Aucun e-mail ne doit annoncer une commande qui n'existe pas.");
    }

    public function testCannotOrderEmptyCart(): void
    {
        $this->expectException(\DomainException::class);

        $this->orderService->placeOrder($this->findCustomer(), new Cart([]), $this->createAddress());
    }

    public function testCancelRestoresExactStock(): void
    {
        $mug = $this->findVariant('MUG-NOIR-MAT');          // stock 12
        $pen = $this->findVariant('STYLO-A-BILLE-BLEU');    // stock 200
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($mug, 2), new CartLine($pen, 3)]),
            $this->createAddress(),
        );

        $this->orderService->cancel($order);

        // On relit la base avec un EntityManager neuf : le changement doit être enregistré, pas seulement en mémoire
        $entityManager = static::getContainer()->get('doctrine')->resetManager();
        $savedOrder = $entityManager->getRepository(Order::class)->find($order->getId());
        $savedMug = $entityManager->getRepository(ProductVariant::class)->findOneBy(['sku' => 'MUG-NOIR-MAT']);
        $savedPen = $entityManager->getRepository(ProductVariant::class)->findOneBy(['sku' => 'STYLO-A-BILLE-BLEU']);
        self::assertNotNull($savedOrder);
        self::assertNotNull($savedMug);
        self::assertNotNull($savedPen);

        self::assertSame(OrderStatus::Cancelled, $savedOrder->getStatus());
        self::assertNotNull($savedOrder->getCancelledAt());
        self::assertSame(12, $savedMug->getStock());
        self::assertSame(200, $savedPen->getStock());
    }

    public function testCancelIgnoresItemsWhoseProductWasDeleted(): void
    {
        $mug = $this->findVariant('MUG-NOIR-MAT');
        $pen = $this->findVariant('STYLO-A-BILLE-BLEU');
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($mug, 2), new CartLine($pen, 3)]),
            $this->createAddress(),
        );
        $orderId = $order->getId();

        // Suppression du stylo : la base supprime ses variantes (onDelete: CASCADE)
        // et met product_id et variant_id à NULL dans la ligne de commande (onDelete: SET NULL)
        $this->entityManager->remove($pen->getProduct() ?? throw new \LogicException('Variante sans produit.'));
        $this->entityManager->flush();
        $this->entityManager->clear();

        $order = $this->entityManager->getRepository(Order::class)->find($orderId);
        self::assertNotNull($order);
        $this->orderService->cancel($order);

        self::assertSame(OrderStatus::Cancelled, $order->getStatus());
        self::assertSame(12, $this->findVariant('MUG-NOIR-MAT')->getStock());
    }

    public function testCancelChangesNothingWhenTransitionIsForbidden(): void
    {
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($this->findVariant('MUG-NOIR-MAT'), 2)]),
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
        $savedMug = $entityManager->getRepository(ProductVariant::class)->findOneBy(['sku' => 'MUG-NOIR-MAT']);
        self::assertNotNull($savedOrder);
        self::assertNotNull($savedMug);

        self::assertSame(OrderStatus::Paid, $savedOrder->getStatus());
        self::assertNull($savedOrder->getCancelledAt());
        // Un seul e-mail : la confirmation de commande. L'annulation refusée n'en envoie aucun.
        self::assertEmailCount(1);
        self::assertSame(10, $savedMug->getStock(), 'Le stock ne doit pas être remis.');
    }

    public function testShipThenDeliverPaidOrder(): void
    {
        $order = $this->orderService->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($this->findVariant('MUG-NOIR-MAT'), 1)]),
            $this->createAddress(),
        );
        $order->markAsPaid(new \DateTimeImmutable());

        $this->orderService->ship($order);
        self::assertSame(OrderStatus::Shipped, $order->getStatus());

        $this->orderService->deliver($order);
        self::assertSame(OrderStatus::Delivered, $order->getStatus());
    }

    public function testPlaceOrderDoesNotTouchProfileByDefault(): void
    {
        $customer = $this->findCustomer();

        $this->orderService->placeOrder($customer, new Cart([new CartLine($this->findVariant('MUG-NOIR-MAT'), 1)]), $this->createAddress());

        $this->entityManager->clear();
        self::assertNull($this->findCustomer()->getAddress());
    }

    public function testPlaceOrderSavesAddressToProfileWhenAsked(): void
    {
        $customer = $this->findCustomer();
        $address = $this->createAddress();
        $address->saveToProfile = true;

        $this->orderService->placeOrder($customer, new Cart([new CartLine($this->findVariant('MUG-NOIR-MAT'), 1)]), $address);

        $this->entityManager->clear();
        $saved = $this->findCustomer();
        self::assertSame('12 rue des Lilas', $saved->getAddress());
        self::assertSame('69001', $saved->getPostalCode());
        self::assertSame('Lyon', $saved->getCity());
        self::assertSame('Camille', $saved->getFirstName(), 'Le nom du destinataire ne remplace pas le nom du client.');
    }

    public function testFailedOrderDoesNotSaveAddressToProfile(): void
    {
        $address = $this->createAddress();
        $address->saveToProfile = true;
        $cart = new Cart([new CartLine($this->findVariant('MUG-ISOTHERME'), 1)]); // stock à 0

        try {
            $this->orderService->placeOrder($this->findCustomer(), $cart, $address);
            self::fail('Une InsufficientStockException était attendue.');
        } catch (InsufficientStockException) {
        }

        $entityManager = static::getContainer()->get('doctrine')->resetManager();
        $savedCustomer = $entityManager->getRepository(User::class)->findOneBy(['email' => 'client@example.com']);
        self::assertNotNull($savedCustomer);
        self::assertNull($savedCustomer->getAddress());
    }

    public function testProfileSaveFailureDoesNotFailTheOrder(): void
    {
        // EntityManager dont seul le flush() appelé par OrderService échoue ; la transaction de la commande,
        // elle, utilise le flush() de l'EntityManager réel et réussit.
        $failingEntityManager = new class($this->entityManager) extends EntityManagerDecorator {
            public function flush(): void
            {
                throw new ConnectionLost(new class('Connexion à la base perdue.') extends AbstractException {}, null);
            }
        };
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');
        $container = static::getContainer();
        $orderService = new OrderService(
            $failingEntityManager,
            $container->get(ProductVariantRepository::class),
            $container->get(OrderRepository::class),
            $container->get(PromoCodeRepository::class),
            new DiscountCalculator(),
            $container->get(CustomerMailer::class),
            $logger,
        );
        $address = $this->createAddress();
        $address->saveToProfile = true;

        $order = $orderService->placeOrder($this->findCustomer(), new Cart([new CartLine($this->findVariant('MUG-NOIR-MAT'), 1)]), $address);

        self::assertNotNull($order->getId(), 'La commande doit être enregistrée malgré l\'échec du profil.');
        self::assertEmailCount(1, message: 'La confirmation de commande doit quand même partir.');
    }

    public function testPromoCodeUsageIsCountedAndDiscountIsCopied(): void
    {
        $promoCode = $this->createPromoCode(maxUsages: null);
        $cart = new Cart([new CartLine($this->findVariant('MUG-NOIR-MAT'), 2)], $promoCode, 298);

        $order = $this->orderService->placeOrder($this->findCustomer(), $cart, $this->createAddress());

        self::assertSame(1, $promoCode->getUsageCount());
        self::assertSame('MOINS10', $order->getDiscountCode());
        // 29,80 € − 10 % = 29,80 € − 2,98 € = 26,82 €
        self::assertSame(2980, $order->getSubtotalAmount());
        self::assertSame(298, $order->getDiscountAmount());
        self::assertSame(2682, $order->getTotalAmount());
    }

    public function testCancelGivesPromoCodeUsageBack(): void
    {
        $promoCode = $this->createPromoCode(maxUsages: null);
        $cart = new Cart([new CartLine($this->findVariant('MUG-NOIR-MAT'), 1)], $promoCode, 149);
        $order = $this->orderService->placeOrder($this->findCustomer(), $cart, $this->createAddress());

        $this->orderService->cancel($order);

        self::assertSame(0, $promoCode->getUsageCount());
    }

    public function testOrderIsRefusedWhenPromoCodeMaxUsagesIsReached(): void
    {
        $promoCode = $this->createPromoCode(maxUsages: 1);
        $cart = new Cart([new CartLine($this->findVariant('MUG-NOIR-MAT'), 1)], $promoCode, 149);
        $this->orderService->placeOrder($this->findCustomer(), $cart, $this->createAddress());
        $orderCountBefore = $this->entityManager->getRepository(Order::class)->count([]);

        try {
            $this->orderService->placeOrder($this->findCustomer(), $cart, $this->createAddress());
            self::fail('Une InvalidPromoCodeException était attendue.');
        } catch (InvalidPromoCodeException $exception) {
            self::assertStringContainsString("nombre maximal d'utilisations", $exception->getMessage());
        }

        // La transaction est annulée : ni commande, ni stock décrémenté, ni utilisation en plus
        $entityManager = static::getContainer()->get('doctrine')->resetManager();
        $mug = $entityManager->getRepository(ProductVariant::class)->findOneBy(['sku' => 'MUG-NOIR-MAT']);
        $savedPromoCode = $entityManager->getRepository(PromoCode::class)->findOneBy(['code' => 'MOINS10']);
        self::assertNotNull($mug);
        self::assertNotNull($savedPromoCode);
        self::assertSame(11, $mug->getStock());
        self::assertSame(1, $savedPromoCode->getUsageCount());
        self::assertSame($orderCountBefore, $entityManager->getRepository(Order::class)->count([]));
    }

    private function createPromoCode(?int $maxUsages): PromoCode
    {
        $promoCode = (new PromoCode())
            ->setCode('MOINS10')
            ->setDiscountType(DiscountType::Percentage)
            ->setValue(10)
            ->setMaxUsages($maxUsages);
        $this->entityManager->persist($promoCode);
        $this->entityManager->flush();

        return $promoCode;
    }

    /**
     * Les références des fixtures reprennent le slug du produit (et le libellé de la variante) en majuscules.
     */
    private function findVariant(string $sku): ProductVariant
    {
        $variant = $this->entityManager->getRepository(ProductVariant::class)->findOneBy(['sku' => $sku]);
        self::assertNotNull($variant);

        return $variant;
    }

    private function findCustomer(): User
    {
        $customer = $this->entityManager->getRepository(User::class)->findOneBy(['email' => 'client@example.com']);
        self::assertNotNull($customer);

        return $customer;
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
