<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Order;
use App\Payment\FakePaymentProvider;
use PHPUnit\Framework\TestCase;

final class FakePaymentProviderTest extends TestCase
{
    public function testAcceptsPaymentUpToLimit(): void
    {
        $order = (new Order())->setTotalAmount(10000);

        $result = (new FakePaymentProvider())->charge($order);

        self::assertTrue($result->successful);
    }

    public function testDeclinesPaymentAboveLimit(): void
    {
        $order = (new Order())->setTotalAmount(10001);

        $result = (new FakePaymentProvider())->charge($order);

        self::assertFalse($result->successful);
        self::assertNotNull($result->errorMessage);
    }
}