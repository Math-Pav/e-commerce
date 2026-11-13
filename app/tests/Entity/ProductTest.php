<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Product;
use App\Exception\InsufficientStockException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProductTest extends TestCase
{
    public function testDecreaseStock(): void
    {
        $product = (new Product())->setStock(5);

        $product->decreaseStock(3);

        self::assertSame(2, $product->getStock());
    }

    public function testCanDecreaseStockToZero(): void
    {
        $product = (new Product())->setStock(5);

        $product->decreaseStock(5);

        self::assertSame(0, $product->getStock());
    }

    public function testCannotDecreaseStockBelowZero(): void
    {
        $product = (new Product())->setName('Mug')->setStock(5);

        try {
            $product->decreaseStock(6);
            self::fail('Une InsufficientStockException était attendue.');
        } catch (InsufficientStockException) {
            self::assertSame(5, $product->getStock(), 'Le stock ne doit pas changer après un échec.');
        }
    }

    public function testCannotDecreaseByZero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new Product())->setStock(5)->decreaseStock(0);
    }

    #[DataProvider('lowStockProvider')]
    public function testIsLowStock(int $stock, bool $expected): void
    {
        $product = (new Product())->setStock($stock);

        self::assertSame($expected, $product->isLowStock());
    }

    /**
     * @return iterable<string, array{int, bool}>
     */
    public static function lowStockProvider(): iterable
    {
        yield 'rupture : pas un stock faible' => [0, false];
        yield 'un seul exemplaire' => [1, true];
        yield 'au seuil' => [Product::LOW_STOCK_THRESHOLD, true];
        yield 'juste au-dessus du seuil' => [Product::LOW_STOCK_THRESHOLD + 1, false];
    }

    public function testIncreaseStock(): void
    {
        $product = (new Product())->setStock(2);

        $product->increaseStock(3);

        self::assertSame(5, $product->getStock());
    }

    #[DataProvider('invalidQuantities')]
    public function testCannotIncreaseStockWithInvalidQuantity(int $quantity): void
    {
        $product = (new Product())->setStock(2);

        try {
            $product->increaseStock($quantity);
            self::fail('Une InvalidArgumentException était attendue.');
        } catch (\InvalidArgumentException) {
            self::assertSame(2, $product->getStock());
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
}
