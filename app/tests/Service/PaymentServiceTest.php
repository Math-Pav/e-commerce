<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Order;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Exception\PaymentFailedException;
use App\Payment\PaymentProviderInterface;
use App\Payment\PaymentResult;
use App\Service\CustomerMailer;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Mailer\MailerInterface;

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
            new NullLogger(),
            'boutique@example.test',
            'Boutique de test',
        );

        return new PaymentService($provider, $this->createStub(EntityManagerInterface::class), $customerMailer);
    }

    private function createOrder(): Order
    {
        // Le client est nécessaire depuis l'envoi de l'e-mail de confirmation de paiement
        $customer = (new User())->setEmail('camille@example.com')->setFirstName('Camille')->setLastName('Martin');

        return (new Order())->setCustomer($customer)->setReference('CMD-TEST-1');
    }
}
