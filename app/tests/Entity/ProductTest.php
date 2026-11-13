<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Product;
use App\Entity\ProductVariant;
use PHPUnit\Framework\TestCase;

/**
 * Disponibilité et prix d'un produit, calculés à partir de ses variantes (ADR 0017).
 */
final class ProductTest extends TestCase
{
    public function testInStockWhileAtLeastOneActiveVariantIs(): void
    {
        $product = $this->createProduct(1290, [[null, 0], [null, 3]]);

        self::assertTrue($product->isInStock());
        self::assertSame($product->getVariants()[1], $product->getFirstVariantInStock());
    }

    public function testOutOfStockWhenAllActiveVariantsAreSoldOut(): void
    {
        $product = $this->createProduct(1290, [[null, 0], [null, 4]]);
        $product->getVariants()[1]?->setIsActive(false);

        self::assertFalse($product->isInStock());
        self::assertNull($product->getFirstVariantInStock());
        self::assertSame(0, $product->getTotalStock());
    }

    public function testFromPriceIsLowestActiveVariantPrice(): void
    {
        $product = $this->createProduct(1290, [[null, 1], [990, 1], [590, 1]]);
        $product->getVariants()[2]?->setIsActive(false);

        self::assertSame(990, $product->getFromPrice());
        self::assertTrue($product->hasVariedPrices());
    }

    public function testPricesDoNotVaryWhenVariantsUseProductPrice(): void
    {
        $product = $this->createProduct(1290, [[null, 1], [1290, 1]]);

        self::assertSame(1290, $product->getFromPrice());
        self::assertFalse($product->hasVariedPrices());
    }

    public function testVariantChoiceOnlyWithSeveralActiveVariants(): void
    {
        $product = $this->createProduct(1290, [[null, 1], [null, 1]]);
        self::assertTrue($product->hasVariantChoice());

        $product->getVariants()[0]?->setIsActive(false);
        self::assertFalse($product->hasVariantChoice());
    }

    /**
     * @param list<array{?int, int}> $variants [prix propre, stock]
     */
    private function createProduct(int $price, array $variants): Product
    {
        $product = (new Product())->setName('Mug')->setPrice($price);

        foreach ($variants as [$variantPrice, $stock]) {
            $product->addVariant((new ProductVariant())->setPrice($variantPrice)->setStock($stock));
        }

        return $product;
    }
}
