<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ProductVariant;
use App\Model\ProductVariantData;
use App\Repository\ProductVariantRepository;
use App\Service\ProductVariantService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Verrouillage optimiste des variantes (A-01, ADR 0023). Test d'intégration : vraie base de test,
 * chaque test est annulé à la fin par DAMA.
 */
final class ProductVariantServiceTest extends KernelTestCase
{
    public function testUpdateWithCurrentVersionSucceedsAndIncrementsVersion(): void
    {
        $variant = $this->findVariant('MUG-NOIR-MAT');
        $version = $variant->getVersion();
        $data = ProductVariantData::fromVariant($variant);
        $data->stock = 20;

        static::getContainer()->get(ProductVariantService::class)->update($variant, $data);

        self::assertSame(20, $variant->getStock());
        self::assertSame($version + 1, $variant->getVersion());
    }

    public function testStaleVersionIsRejected(): void
    {
        $variant = $this->findVariant('MUG-NOIR-MAT');
        $stock = $variant->getStock();
        $data = ProductVariantData::fromVariant($variant);
        $data->version = $variant->getVersion() - 1;
        $data->stock = 99;

        try {
            static::getContainer()->get(ProductVariantService::class)->update($variant, $data);
            self::fail('Une version périmée doit être refusée.');
        } catch (OptimisticLockException) {
        }

        self::assertSame($stock, $this->reloadStock($variant));
    }

    /**
     * Vente enregistrée par une autre requête après le chargement de la variante, juste avant l'enregistrement :
     * c'est le flush (UPDATE … WHERE version = ?) qui la détecte.
     */
    public function testSaleCommittedJustBeforeFlushIsNotOverwritten(): void
    {
        $variant = $this->findVariant('MUG-NOIR-MAT');
        $stock = $variant->getStock();
        $data = ProductVariantData::fromVariant($variant);
        $data->stock = 99;

        static::getContainer()->get(EntityManagerInterface::class)->getConnection()->executeStatement(
            'UPDATE product_variant SET stock = stock - 3, version = version + 1 WHERE id = ?',
            [$variant->getId()],
        );

        try {
            static::getContainer()->get(ProductVariantService::class)->update($variant, $data);
            self::fail('Une variante modifiée entre-temps ne doit pas être écrasée.');
        } catch (OptimisticLockException) {
        }

        self::assertSame($stock - 3, $this->reloadStock($variant));
    }

    private function findVariant(string $sku): ProductVariant
    {
        $variant = static::getContainer()->get(ProductVariantRepository::class)->findOneBy(['sku' => $sku]);
        self::assertNotNull($variant);

        return $variant;
    }

    private function reloadStock(ProductVariant $variant): int
    {
        return (int) static::getContainer()->get(EntityManagerInterface::class)->getConnection()->fetchOne(
            'SELECT stock FROM product_variant WHERE id = ?',
            [$variant->getId()],
        );
    }
}
