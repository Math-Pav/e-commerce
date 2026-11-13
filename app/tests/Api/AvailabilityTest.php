<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\Output\Availability;
use App\Entity\Product;
use App\Entity\ProductVariant;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AvailabilityTest extends TestCase
{
    /**
     * @return iterable<string, array{int, Availability}>
     */
    public static function provideStocks(): iterable
    {
        yield 'épuisé' => [0, Availability::OutOfStock];
        yield 'une unité' => [1, Availability::LowStock];
        yield 'au seuil' => [ProductVariant::LOW_STOCK_THRESHOLD, Availability::LowStock];
        yield 'au-dessus du seuil' => [ProductVariant::LOW_STOCK_THRESHOLD + 1, Availability::Available];
    }

    #[DataProvider('provideStocks')]
    public function testVariantAvailabilityDependsOnStock(int $stock, Availability $expected): void
    {
        self::assertSame($expected, Availability::fromVariant((new ProductVariant())->setStock($stock)));
    }

    public function testProductTakesBestStateOfItsActiveVariants(): void
    {
        $product = $this->product([0, 3]);
        self::assertSame(Availability::LowStock, Availability::fromProduct($product));

        $product->addVariant((new ProductVariant())->setStock(20));
        self::assertSame(Availability::Available, Availability::fromProduct($product));
    }

    public function testInactiveVariantsAreIgnored(): void
    {
        $product = $this->product([0]);
        $product->addVariant((new ProductVariant())->setStock(20)->setIsActive(false));

        self::assertSame(Availability::OutOfStock, Availability::fromProduct($product));
    }

    /**
     * @param list<int> $stocks
     */
    private function product(array $stocks): Product
    {
        $product = (new Product())->setPrice(1000);

        foreach ($stocks as $stock) {
            $product->addVariant((new ProductVariant())->setStock($stock));
        }

        return $product;
    }
}
