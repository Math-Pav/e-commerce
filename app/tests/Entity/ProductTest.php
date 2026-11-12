<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Product;
use App\Exception\InsufficientStockException;
use PHPUnit\Framework\TestCase;

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
}