<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Exception\InsufficientStockException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ProductVariantTest extends TestCase
{
    public function testDecreaseStock(): void
    {
        $variant = (new ProductVariant())->setStock(5);

        $variant->decreaseStock(3);

        self::assertSame(2, $variant->getStock());
    }

    public function testCanDecreaseStockToZero(): void
    {
        $variant = (new ProductVariant())->setStock(5);

        $variant->decreaseStock(5);

        self::assertSame(0, $variant->getStock());
    }

    public function testCannotDecreaseStockBelowZero(): void
    {
        $variant = $this->createVariant(5);

        try {
            $variant->decreaseStock(6);
            self::fail('Une InsufficientStockException était attendue.');
        } catch (InsufficientStockException) {
            self::assertSame(5, $variant->getStock(), 'Le stock ne doit pas changer après un échec.');
        }
    }

    public function testCannotDecreaseByZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProductVariant())->setStock(5)->decreaseStock(0);
    }

    #[DataProvider('lowStockProvider')]
    public function testIsLowStock(int $stock, bool $expected): void
    {
        $variant = (new ProductVariant())->setStock($stock);

        self::assertSame($expected, $variant->isLowStock());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function lowStockProvider(): iterable
    {
        yield 'rupture : pas un stock faible' => [0, false];
        yield 'un seul exemplaire' => [1, true];
        yield 'au seuil' => [ProductVariant::LOW_STOCK_THRESHOLD, true];
        yield 'juste au-dessus du seuil' => [ProductVariant::LOW_STOCK_THRESHOLD + 1, false];
    }

    public function testIncreaseStock(): void
    {
        $variant = (new ProductVariant())->setStock(2);

        $variant->increaseStock(3);

        self::assertSame(5, $variant->getStock());
    }

    #[DataProvider('invalidQuantities')]
    public function testCannotIncreaseStockWithInvalidQuantity(int $quantity): void
    {
        $variant = (new ProductVariant())->setStock(2);

        try {
            $variant->increaseStock($quantity);
            self::fail('Une InvalidArgumentException était attendue.');
        } catch (\InvalidArgumentException) {
            self::assertSame(2, $variant->getStock());
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function invalidQuantities(): iterable
    {
        yield 'zéro' => [0];
        yield 'négative' => [-1];
    }

    public function testEffectivePriceFallsBackToProductPrice(): void
    {
        $variant = $this->createVariant(5);

        self::assertSame(1290, $variant->getEffectivePrice());

        $variant->setPrice(1590);
        self::assertSame(1590, $variant->getEffectivePrice());
    }

    public function testNameIncludesLabelOnlyWhenThereIsOne(): void
    {
        $variant = $this->createVariant(5);
        self::assertSame('Mug', $variant->getName());

        $variant->setLabel('Bleu');
        self::assertSame('Mug – Bleu', $variant->getName());
    }

    public function testIsAvailableOnlyIfVariantAndProductAreActive(): void
    {
        $variant = $this->createVariant(5);
        self::assertTrue($variant->isAvailable());

        $variant->setIsActive(false);
        self::assertFalse($variant->isAvailable());

        $variant->setIsActive(true);
        $variant->getProduct()?->setIsActive(false);
        self::assertFalse($variant->isAvailable());
    }

    private function createVariant(int $stock): ProductVariant
    {
        $variant = (new ProductVariant())->setStock($stock);
        (new Product())->setName('Mug')->setPrice(1290)->addVariant($variant);

        return $variant;
    }
}
