<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ProductVariant;
use App\Repository\ProductVariantRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ProductVariantRepositoryTest extends KernelTestCase
{
    private ProductVariantRepository $variantRepository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->variantRepository = static::getContainer()->get(ProductVariantRepository::class);
    }

    public function testFindOutOfStockOrLowStockMatchesVariantRules(): void
    {
        $this->findVariant('MUG-NOIR-MAT')->setStock(0);
        $this->findVariant('CARNET-A5-LIGNE')->setStock(ProductVariant::LOW_STOCK_THRESHOLD);
        $this->findVariant('TASSE-A-EXPRESSO')->setStock(ProductVariant::LOW_STOCK_THRESHOLD + 1);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $variants = $this->variantRepository->findOutOfStockOrLowStock();

        $skus = array_map(static fn (ProductVariant $variant): string => (string) $variant->getSku(), $variants);
        self::assertContains('MUG-NOIR-MAT', $skus);
        self::assertContains('CARNET-A5-LIGNE', $skus);
        self::assertNotContains('TASSE-A-EXPRESSO', $skus);
        // Une seule variante du mug émaillé est épuisée : elle seule est signalée
        self::assertContains('MUG-EMAILLE-ROUGE', $skus);
        self::assertNotContains('MUG-EMAILLE-BLEU', $skus);

        // Même règle que l'entité : chaque variante renvoyée est en rupture ou en stock faible
        foreach ($variants as $variant) {
            self::assertTrue(!$variant->isInStock() || $variant->isLowStock());
        }

        // Les plus urgentes d'abord
        $stocks = array_map(static fn (ProductVariant $variant): int => $variant->getStock(), $variants);
        $sortedStocks = $stocks;
        sort($sortedStocks);
        self::assertSame($sortedStocks, $stocks);
    }

    public function testFindAvailableByIdsIgnoresUnknownAndInactiveVariants(): void
    {
        $blue = $this->findVariant('MUG-EMAILLE-BLEU');
        $green = $this->findVariant('MUG-EMAILLE-VERT');
        $agenda = $this->findVariant('AGENDA-2026'); // produit désactivé dans les fixtures
        $green->setIsActive(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $variants = $this->variantRepository->findAvailableByIds([(int) $blue->getId(), (int) $green->getId(), (int) $agenda->getId(), 999999]);

        self::assertSame([$blue], $variants);
    }

    private function findVariant(string $sku): ProductVariant
    {
        $variant = $this->variantRepository->findOneBy(['sku' => $sku]);
        self::assertNotNull($variant, sprintf('La variante %s doit exister dans les fixtures.', $sku));

        return $variant;
    }
}
