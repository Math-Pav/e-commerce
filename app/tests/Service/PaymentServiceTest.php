<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Exception\PaymentFailedException;
use App\Payment\PaymentProviderInterface;
use App\Payment\PaymentResult;
use App\Service\PaymentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class PaymentServiceTest extends TestCase
{
    public function testSuccessfulPaymentMarksOrderAsPaid(): void
    {
        $provider = $this->createStub(PaymentProviderInterface::class);
        $provider->method('charge')->willReturn(PaymentResult::success());
        $order = new Order();

        $this->createPaymentService($provider)->pay($order);

        self::assertSame(OrderStatus::Paid, $order->getStatus());
        self::assertNotNull($order->getPaidAt());
    }

    public function testDeclinedPaymentKeepsOrderPending(): void
    {
        $provider = $this->createStub(PaymentProviderInterface::class);
        $provider->method('charge')->willReturn(PaymentResult::failure('carte refusée'));
        $order = new Order();

        try {
            $this->createPaymentService($provider)->pay($order);
            self::fail('Une PaymentFailedException était attendue.');
        } catch (PaymentFailedException) {
            self::assertSame(OrderStatus::Pending, $order->getStatus());
            self::assertNull($order->getPaidAt());
        }
    }

    public function testPaidOrderIsNeverChargedTwice(): void
    {
        $order = new Order();
        $order->markAsPaid(new \DateTimeImmutable());

        // Un mock vérifie un comportement : ici, que le fournisseur n'est JAMAIS appelé
        $provider = $this->createMock(PaymentProviderInterface::class);
        $provider->expects(self::never())->method('charge');

        $this->expectException(\DomainException::class);

        $this->createPaymentService($provider)->pay($order);
    }

    private function createPaymentService(PaymentProviderInterface $provider): PaymentService
    {
        return new PaymentService($provider, $this->createStub(EntityManagerInterface::class));
    }
}