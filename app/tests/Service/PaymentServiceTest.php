<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Exception\PaymentFailedException;
use App\Payment\PaymentProviderInterface;
use App\Payment\PaymentResult;
use App\Repository\InvoiceSequenceRepository;
use App\Repository\OrderRepository;
use App\Service\CustomerMailer;
use App\Service\InvoiceService;
use App\Service\PaymentService;
use App\Service\VatCalculator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

final class PaymentServiceTest extends TestCase
{
    public function testSuccessfulPaymentMarksOrderAsPaid(): void
    {
        $provider = $this->createStub(PaymentProviderInterface::class);
        $provider->method('charge')->willReturn(PaymentResult::success());
        $order = $this->createOrder();

        $this->createPaymentService($provider)->pay($order);

        self::assertSame(OrderStatus::Paid, $order->getStatus());
        self::assertNotNull($order->getPaidAt());
    }

    public function testSuccessfulPaymentSendsInvoiceWithEmail(): void
    {
        $provider = $this->createStub(PaymentProviderInterface::class);
        $provider->method('charge')->willReturn(PaymentResult::success());
        $sentEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send')->willReturnCallback(static function (RawMessage $message) use (&$sentEmail): void {
            $sentEmail = $message;
        });

        $this->createPaymentService($provider, $mailer)->pay($this->createOrder());

        self::assertInstanceOf(TemplatedEmail::class, $sentEmail);
        $invoice = $sentEmail->getContext()['invoice'];
        self::assertInstanceOf(Invoice::class, $invoice);
        self::assertSame(sprintf('FA-%s-000001', date('Y')), $invoice->getNumber());
        self::assertSame(2980, $invoice->getTotalIncludingTax());
        self::assertSame(2483, $invoice->getTotalExcludingTax());
        self::assertSame(497, $invoice->getVatAmount());
    }

    public function testSuccessfulPaymentSendsConfirmationEmail(): void
    {
        $provider = $this->createStub(PaymentProviderInterface::class);
        $provider->method('charge')->willReturn(PaymentResult::success());
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())->method('send');

        $this->createPaymentService($provider, $mailer)->pay($this->createOrder());
    }

    public function testDeclinedPaymentKeepsOrderPending(): void
    {
        $provider = $this->createStub(PaymentProviderInterface::class);
        $provider->method('charge')->willReturn(PaymentResult::failure('carte refusée'));
        $order = $this->createOrder();
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::never())->method('send');

        try {
            $this->createPaymentService($provider, $mailer)->pay($order);
            self::fail('Une PaymentFailedException était attendue.');
        } catch (PaymentFailedException) {
            self::assertSame(OrderStatus::Pending, $order->getStatus());
            self::assertNull($order->getPaidAt());
        }
    }

    public function testPaidOrderIsNeverChargedTwice(): void
    {
        $order = $this->createOrder();
        $order->markAsPaid(new \DateTimeImmutable());

        // Un mock vérifie un comportement : ici, que le fournisseur n'est JAMAIS appelé
        $provider = $this->createMock(PaymentProviderInterface::class);
        $provider->expects(self::never())->method('charge');

        $this->expectException(\DomainException::class);

        $this->createPaymentService($provider)->pay($order);
    }

    private function createPaymentService(PaymentProviderInterface $provider, ?MailerInterface $mailer = null): PaymentService
    {
        $customerMailer = new CustomerMailer(
            $mailer ?? $this->createStub(MailerInterface::class),
            CustomerMailerTest::createPdfGenerator(),
            new NullLogger(),
            'boutique@example.test',
            'Boutique de test',
        );

        // La transaction exécute simplement la fonction reçue, comme le ferait Doctrine
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('wrapInTransaction')->willReturnCallback(static fn (callable $func): mixed => $func($entityManager));
        $sequenceRepository = $this->createStub(InvoiceSequenceRepository::class);
        $sequenceRepository->method('nextNumber')->willReturn(1);
        $invoiceService = new InvoiceService(
            $entityManager,
            $sequenceRepository,
            new VatCalculator(),
            20,
            'Vendeur', '1 rue A', '69000', 'Lyon', '12345678900017', 'FR40123456789',
        );

        return new PaymentService(
            $provider,
            $entityManager,
            $customerMailer,
            $this->createStub(OrderRepository::class),
            $invoiceService,
        );
    }

    private function createOrder(): Order
    {
        // Le client est nécessaire depuis l'envoi de l'e-mail de confirmation de paiement
        $customer = (new User())->setEmail('camille@example.com')->setFirstName('Camille')->setLastName('Martin');

        $order = (new Order())
            ->setCustomer($customer)
            ->setReference('CMD-TEST-1')
            ->setShippingFullName('Camille Martin')
            ->setShippingAddress('12 rue des Lilas')
            ->setShippingPostalCode('69001')
            ->setShippingCity('Lyon')
            ->addItem((new OrderItem())->setProductName('Mug')->setUnitPrice(1490)->setQuantity(2));
        $order->recalculateTotal();

        return $order;
    }
}
