<?php

declare(strict_types=1);

namespace App\Tests\Payment;

use App\Entity\Order;
use App\Payment\FakePaymentProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FakePaymentProviderTest extends TestCase
{
    /**
     * @return iterable<string, array{int}>
     */
    public static function normalAmountProvider(): iterable
    {
        yield '1 centime' => [1];
        yield '100,01 € (ancien plafond dépassé, A-02)' => [10001];
        yield '139,60 €' => [13960];
        yield 'un centime de moins que le montant de test' => [FakePaymentProvider::DECLINED_AMOUNT - 1];
        yield 'un centime de plus que le montant de test' => [FakePaymentProvider::DECLINED_AMOUNT + 1];
    }

    #[DataProvider('normalAmountProvider')]
    public function testAcceptsNormalAmounts(int $amount): void
    {
        $result = (new FakePaymentProvider())->charge((new Order())->setTotalAmount($amount));

        self::assertTrue($result->successful);
    }

    public function testDeclinesOnlyTheTestAmount(): void
    {
        $result = (new FakePaymentProvider())->charge((new Order())->setTotalAmount(999999));

        self::assertFalse($result->successful);
        self::assertNotNull($result->errorMessage);
    }
}
