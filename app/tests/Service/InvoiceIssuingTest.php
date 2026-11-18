<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\InvoiceLine;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Exception\PaymentFailedException;
use App\Payment\FakePaymentProvider;
use App\Repository\InvoiceRepository;
use App\Repository\UserRepository;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Test d'intégration : vrai conteneur, vraie base de test (chaque test est annulé à la fin par DAMA).
 */
final class InvoiceIssuingTest extends KernelTestCase
{
    public function testTwoPaymentsGetConsecutiveNumbers(): void
    {
        $paymentService = static::getContainer()->get(PaymentService::class);
        $firstOrder = $this->createPendingOrder(1290);
        $secondOrder = $this->createPendingOrder(450);

        $paymentService->pay($firstOrder);
        $paymentService->pay($secondOrder);

        $first = $this->findInvoiceNumber($firstOrder);
        $second = $this->findInvoiceNumber($secondOrder);
        self::assertMatchesRegularExpression('/^FA-\d{4}-\d{6}$/', $first);
        self::assertSame(substr($first, 0, 8), substr($second, 0, 8), 'Même année');
        self::assertSame((int) substr($first, -6) + 1, (int) substr($second, -6));
    }

    public function testInvoiceCopiesOrderAndComputesVat(): void
    {
        $order = $this->createPendingOrder(1490, quantity: 2, variantLabel: 'Bleu');

        static::getContainer()->get(PaymentService::class)->pay($order);

        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $invoice = static::getContainer()->get(InvoiceRepository::class)->findOneBy(['orderReference' => $order->getReference()]);
        self::assertNotNull($invoice);
        self::assertSame('Camille Martin', $invoice->getCustomerName());
        self::assertSame('12 rue des Lilas', $invoice->getBillingAddress());
        self::assertSame('Atelier Papier SAS', $invoice->getSellerName());
        self::assertSame(2980, $invoice->getTotalIncludingTax());
        self::assertSame(2483, $invoice->getTotalExcludingTax());
        self::assertSame(497, $invoice->getVatAmount());
        self::assertSame(20, $invoice->getVatRate());
        self::assertCount(1, $invoice->getLines());
        $line = $invoice->getLines()->first();
        self::assertInstanceOf(InvoiceLine::class, $line);
        self::assertSame('Mug émaillé – Bleu', $line->getDisplayName());
    }

    public function testUnpaidOrderHasNoInvoice(): void
    {
        $order = $this->createPendingOrder(1290);

        self::assertNull(static::getContainer()->get(InvoiceRepository::class)->findOneByOrder($order));
    }

    public function testPayingAgainDoesNotCreateSecondInvoice(): void
    {
        $paymentService = static::getContainer()->get(PaymentService::class);
        $order = $this->createPendingOrder(1290);
        $paymentService->pay($order);

        try {
            $paymentService->pay($order);
            self::fail('Une DomainException était attendue.');
        } catch (\DomainException) {
        }

        self::assertSame(1, static::getContainer()->get(InvoiceRepository::class)->count(['order' => $order]));
    }

    public function testDeclinedPaymentConsumesNoNumber(): void
    {
        $paymentService = static::getContainer()->get(PaymentService::class);
        $first = $this->createPendingOrder(1290);
        // 9 999,99 € : le seul montant que le fournisseur simulé refuse
        $declined = $this->createPendingOrder(FakePaymentProvider::DECLINED_AMOUNT);
        $last = $this->createPendingOrder(1290);

        $paymentService->pay($first);
        try {
            $paymentService->pay($declined);
        } catch (PaymentFailedException) {
        }
        $paymentService->pay($last);

        self::assertSame((int) substr($this->findInvoiceNumber($first), -6) + 1, (int) substr($this->findInvoiceNumber($last), -6));
        self::assertNull(static::getContainer()->get(InvoiceRepository::class)->findOneByOrder($declined));
    }

    private function createPendingOrder(int $unitPrice, int $quantity = 1, ?string $variantLabel = null): Order
    {
        $customer = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'client@example.com']);
        self::assertNotNull($customer);

        $order = (new Order())
            ->setCustomer($customer)
            ->setReference('CMD-TEST-'.strtoupper(bin2hex(random_bytes(3))))
            ->setShippingFullName('Camille Martin')
            ->setShippingAddress('12 rue des Lilas')
            ->setShippingPostalCode('69001')
            ->setShippingCity('Lyon')
            ->addItem((new OrderItem())
                ->setProductName('Mug émaillé')
                ->setVariantLabel($variantLabel)
                ->setUnitPrice($unitPrice)
                ->setQuantity($quantity));
        $order->recalculateTotal();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($order);
        $entityManager->flush();

        return $order;
    }

    private function findInvoiceNumber(Order $order): string
    {
        $invoice = static::getContainer()->get(InvoiceRepository::class)->findOneByOrder($order);
        self::assertNotNull($invoice);

        return $invoice->getNumber();
    }
}
