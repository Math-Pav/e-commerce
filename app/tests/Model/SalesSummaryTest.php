<?php

declare(strict_types=1);

namespace App\Tests\Model;

use App\Model\SalesSummary;
use PHPUnit\Framework\TestCase;

final class SalesSummaryTest extends TestCase
{
    public function testAverageOrderValueIsRoundedToTheNearestCent(): void
    {
        // 10,00 € pour 3 commandes = 3,333… € → 3,33 € ; 20,00 € pour 3 commandes = 6,666… € → 6,67 €
        self::assertSame(333, (new SalesSummary(1000, 3, 0))->getAverageOrderValue());
        self::assertSame(667, (new SalesSummary(2000, 3, 0))->getAverageOrderValue());
    }

    public function testAverageOrderValueIsNullWithoutOrders(): void
    {
        self::assertNull((new SalesSummary(0, 0, 0))->getAverageOrderValue());
    }
}
