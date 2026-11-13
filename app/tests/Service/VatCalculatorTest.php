<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\VatCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class VatCalculatorTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function amountsProvider(): iterable
    {
        // TTC, HT attendu, TVA attendue (taux 20 %)
        yield 'montant rond' => [1200, 1000, 200];
        yield 'zéro' => [0, 0, 0];
        yield 'un centime : HT 0,83 arrondi à 1' => [1, 1, 0];
        yield 'exactement un demi-centime : 2,5 arrondi vers le haut' => [3, 3, 0];
        yield '9,99 € : HT 832,5 arrondi à 833' => [999, 833, 166];
        yield '19,90 € : HT 1658,33 arrondi à 1658' => [1990, 1658, 332];
        yield '14,90 € : HT 1241,67 arrondi à 1242' => [1490, 1242, 248];
        yield 'grand montant' => [123456789, 102880658, 20576131];
    }

    #[DataProvider('amountsProvider')]
    public function testBreaksDownAmountIncludingTax(int $including, int $excluding, int $vat): void
    {
        $breakdown = (new VatCalculator())->fromAmountIncludingTax($including, 20);

        self::assertSame($including, $breakdown->totalIncludingTax);
        self::assertSame($excluding, $breakdown->totalExcludingTax);
        self::assertSame($vat, $breakdown->vatAmount);
        self::assertSame(20, $breakdown->vatRate);
    }

    public function testExcludingTaxPlusVatAlwaysEqualsIncludingTaxAndRoundingStaysUnderHalfACent(): void
    {
        $calculator = new VatCalculator();

        for ($including = 0; $including <= 5000; ++$including) {
            $breakdown = $calculator->fromAmountIncludingTax($including, 20);

            self::assertSame($including, $breakdown->totalExcludingTax + $breakdown->vatAmount);
            // Écart entre HT arrondi et HT exact (× 120 pour rester en entiers) : au plus un demi-centime
            self::assertLessThanOrEqual(60, abs($breakdown->totalExcludingTax * 120 - $including * 100));
        }
    }

    public function testOtherRate(): void
    {
        $breakdown = (new VatCalculator())->fromAmountIncludingTax(1100, 10);

        self::assertSame(1000, $breakdown->totalExcludingTax);
        self::assertSame(100, $breakdown->vatAmount);
    }

    public function testNegativeAmountIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new VatCalculator())->fromAmountIncludingTax(-1, 20);
    }
}
