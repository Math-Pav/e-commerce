<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Service\ProductService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class ProductServiceTest extends TestCase
{
    /**
     * Slugs considérés comme déjà présents en base par le faux repository.
     *
     * @var list<string>
     */
    private array $existingSlugs = [];

    private ProductService $productService;

    protected function setUp(): void
    {
        // Un faux repository : il répond à partir de $existingSlugs, sans toucher à la base
        $repository = $this->createStub(ProductRepository::class);
        $repository->method('isSlugTaken')->willReturnCallback(
            fn (string $slug): bool => in_array($slug, $this->existingSlugs, true),
        );

        $this->productService = new ProductService(
            $this->createStub(EntityManagerInterface::class),
            $repository,
            new AsciiSlugger(),
        );
    }

    public function testCreateGeneratesSlugFromName(): void
    {
        $product = $this->createProduct('Mug à café « Été »');

        $this->productService->create($product);

        self::assertSame('mug-a-cafe-ete', $product->getSlug());
    }

    public function testCreateAddsSuffixWhenSlugIsTaken(): void
    {
        $this->existingSlugs = ['mug-noir', 'mug-noir-2'];
        $product = $this->createProduct('Mug noir');

        $this->productService->create($product);

        self::assertSame('mug-noir-3', $product->getSlug());
    }

    public function testCreateUsesFallbackWhenNameHasNoUsableCharacter(): void
    {
        $product = $this->createProduct('!!!');

        $this->productService->create($product);

        self::assertSame('produit', $product->getSlug());
    }

    public function testCreateLimitsSlugLength(): void
    {
        $product = $this->createProduct(str_repeat('a', 150).' '.str_repeat('é', 20));

        $this->productService->create($product);

        self::assertLessThanOrEqual(160, strlen((string) $product->getSlug()));
    }

    public function testCreatePersistsAndFlushes(): void
    {
        $product = $this->createProduct('Carnet');
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with($product);
        $entityManager->expects(self::once())->method('flush');

        $service = new ProductService($entityManager, $this->createStub(ProductRepository::class), new AsciiSlugger());
        $service->create($product);
    }

    public function testUpdateKeepsSlugWhenNameChanges(): void
    {
        $product = $this->createProduct('Mug noir');
        $this->productService->create($product);

        $product->setName('Mug noir mat');
        $this->productService->update($product);

        self::assertSame('mug-noir', $product->getSlug());
    }

    public function testDeactivate(): void
    {
        $product = $this->createProduct('Mug noir');

        $this->productService->deactivate($product);

        self::assertFalse($product->isActive());
    }

    public function testActivate(): void
    {
        $product = $this->createProduct('Mug noir')->setIsActive(false);

        $this->productService->activate($product);

        self::assertTrue($product->isActive());
    }

    private function createProduct(string $name): Product
    {
        return (new Product())->setName($name)->setPrice(990);
    }
}
