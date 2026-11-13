<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\PromoCode;
use App\Enum\DiscountType;
use App\Service\DiscountCalculator;
use PHPUnit\Framework\TestCase;

final class DiscountCalculatorTest extends TestCase
{
    private DiscountCalculator $calculator;

    protected function setUp(): void
    {
        $this->calculator = new DiscountCalculator();
    }

    public function testPercentageIsRoundedDown(): void
    {
        // 15 % de 33,33 € = 4,9995 € : la remise est de 4,99 €, jamais 5,00 €
        $promoCode = $this->createPromoCode(DiscountType::Percentage, 15);

        self::assertSame(499, $this->calculator->calculate($promoCode, 3333));
    }

    public function testFixedAmount(): void
    {
        $promoCode = $this->createPromoCode(DiscountType::FixedAmount, 500);

        self::assertSame(500, $this->calculator->calculate($promoCode, 3000));
    }

    public function testFixedAmountIsCappedAtSubtotal(): void
    {
        $promoCode = $this->createPromoCode(DiscountType::FixedAmount, 2000);

        self::assertSame(1490, $this->calculator->calculate($promoCode, 1490));
    }

    public function testNoDiscountWhenMinimumIsNotReached(): void
    {
        $promoCode = $this->createPromoCode(DiscountType::FixedAmount, 500)->setMinimumAmount(3000);

        self::assertSame(0, $this->calculator->calculate($promoCode, 2999));
        self::assertSame(500, $this->calculator->calculate($promoCode, 3000));
    }

    private function createPromoCode(DiscountType $type, int $value): PromoCode
    {
        return (new PromoCode())
            ->setCode('TEST')
            ->setDiscountType($type)
            ->setValue($value);
    }
}
