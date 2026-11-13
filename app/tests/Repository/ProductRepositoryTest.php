<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProductRepositoryTest extends KernelTestCase
{
    public function testFindOutOfStockOrLowStockMatchesProductRules(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $productRepository = $container->get(ProductRepository::class);
        $this->setStock($productRepository, 'mug-noir-mat', 0);
        $this->setStock($productRepository, 'carnet-a5-ligne', Product::LOW_STOCK_THRESHOLD);
        $this->setStock($productRepository, 'tasse-a-expresso', Product::LOW_STOCK_THRESHOLD + 1);
        $container->get(EntityManagerInterface::class)->flush();

        $products = $productRepository->findOutOfStockOrLowStock();

        $slugs = array_map(static fn (Product $product): string => (string) $product->getSlug(), $products);
        self::assertContains('mug-noir-mat', $slugs);
        self::assertContains('carnet-a5-ligne', $slugs);
        self::assertNotContains('tasse-a-expresso', $slugs);

        // Même règle que l'entité : chaque produit renvoyé est en rupture ou en stock faible
        foreach ($products as $product) {
            self::assertTrue(!$product->isInStock() || $product->isLowStock());
        }

        // Les plus urgents d'abord
        $stocks = array_map(static fn (Product $product): int => $product->getStock(), $products);
        $sortedStocks = $stocks;
        sort($sortedStocks);
        self::assertSame($sortedStocks, $stocks);
    }

    private function setStock(ProductRepository $productRepository, string $slug, int $stock): void
    {
        $productRepository->findOneBy(['slug' => $slug])->setStock($stock);
    }
}
