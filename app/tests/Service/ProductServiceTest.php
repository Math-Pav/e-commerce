<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Model\ProductData;
use App\Repository\ProductRepository;
use App\Service\ProductImageStorage;
use App\Service\ProductService;
use App\Service\SlugGenerator;
use App\Tests\Util\TestFiles;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

    // Dossier temporaire des photos : le vrai service d'enregistrement y écrit (il est final, donc pas de faux objet)
    private string $imageDirectory;

    private TestFiles $files;

    protected function setUp(): void
    {
        $this->imageDirectory = sys_get_temp_dir().'/product-service-'.bin2hex(random_bytes(8));
        $this->files = new TestFiles();

        // Un faux repository : il répond à partir de $existingSlugs, sans toucher à la base
        $repository = $this->createStub(ProductRepository::class);
        $repository->method('isSlugTaken')->willReturnCallback(
            fn (string $slug): bool => in_array($slug, $this->existingSlugs, true),
        );

        $this->productService = new ProductService(
            $this->createStub(EntityManagerInterface::class),
            $repository,
            new SlugGenerator(new AsciiSlugger()),
            new ProductImageStorage($this->imageDirectory),
        );
    }

    protected function tearDown(): void
    {
        $this->files->cleanUp();
        TestFiles::emptyDirectory($this->imageDirectory);
        if (is_dir($this->imageDirectory)) {
            rmdir($this->imageDirectory);
        }
    }

    public function testCreateBuildsProductFromData(): void
    {
        $data = $this->createData('Mug noir');
        $data->stock = 12;
        $data->description = 'Un mug.';

        $product = $this->productService->create($data);

        self::assertSame('Mug noir', $product->getName());
        self::assertSame($data->category, $product->getCategory());
        self::assertSame(990, $product->getPrice());
        // Le stock initial va à la variante par défaut, sans libellé, avec une référence générée
        self::assertCount(1, $product->getVariants());
        $variant = $product->getVariants()->first();
        self::assertInstanceOf(ProductVariant::class, $variant);
        self::assertNull($variant->getLabel());
        self::assertNull($variant->getPrice());
        self::assertSame(12, $variant->getStock());
        self::assertMatchesRegularExpression('/^PRD-[0-9A-F]{8}$/', (string) $variant->getSku());
        self::assertSame('Un mug.', $product->getDescription());
        self::assertTrue($product->isActive());
    }

    public function testCreateGeneratesSlugFromName(): void
    {
        $product = $this->productService->create($this->createData('Mug à café « Été »'));

        self::assertSame('mug-a-cafe-ete', $product->getSlug());
    }

    public function testCreateAddsSuffixWhenSlugIsTaken(): void
    {
        $this->existingSlugs = ['mug-noir', 'mug-noir-2'];

        $product = $this->productService->create($this->createData('Mug noir'));

        self::assertSame('mug-noir-3', $product->getSlug());
    }

    public function testCreateUsesFallbackWhenNameHasNoUsableCharacter(): void
    {
        $product = $this->productService->create($this->createData('!!!'));

        self::assertSame('produit', $product->getSlug());
    }

    public function testCreateLimitsSlugLength(): void
    {
        $product = $this->productService->create($this->createData(str_repeat('a', 150).' '.str_repeat('é', 20)));

        self::assertLessThanOrEqual(160, strlen((string) $product->getSlug()));
    }

    public function testCreatePersistsAndFlushes(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('persist')->with(self::isInstanceOf(Product::class));
        $entityManager->expects(self::once())->method('flush');

        $service = new ProductService(
            $entityManager,
            $this->createStub(ProductRepository::class),
            new SlugGenerator(new AsciiSlugger()),
            new ProductImageStorage($this->imageDirectory),
        );
        $service->create($this->createData('Carnet'));
    }

    public function testCreateRejectsDataWithoutPrice(): void
    {
        $data = $this->createData('Carnet');
        $data->price = null;

        $this->expectException(\InvalidArgumentException::class);

        $this->productService->create($data);
    }

    public function testUpdateAppliesDataAndKeepsSlug(): void
    {
        $product = $this->productService->create($this->createData('Mug noir'));

        $data = ProductData::fromProduct($product);
        $data->name = 'Mug noir mat';
        $data->price = 1650;
        $this->productService->update($product, $data);

        self::assertSame('Mug noir mat', $product->getName());
        self::assertSame(1650, $product->getPrice());
        self::assertSame('mug-noir', $product->getSlug());
    }

    public function testCreateWithoutImageKeepsPlaceholder(): void
    {
        $product = $this->productService->create($this->createData('Carnet'));

        self::assertNull($product->getImageFilename());
    }

    public function testCreateStoresImage(): void
    {
        $data = $this->createData('Carnet');
        $data->image = $this->upload();

        $product = $this->productService->create($data);

        self::assertNotNull($product->getImageFilename());
        self::assertFileExists($this->imagePath($product->getImageFilename()));
    }

    public function testUpdateWithNewImageRemovesPreviousFile(): void
    {
        $product = $this->createProductWithImage();
        $previousImage = (string) $product->getImageFilename();

        $data = ProductData::fromProduct($product);
        $data->image = $this->upload();
        $this->productService->update($product, $data);

        self::assertNotSame($previousImage, $product->getImageFilename());
        self::assertFileExists($this->imagePath((string) $product->getImageFilename()));
        self::assertFileDoesNotExist($this->imagePath($previousImage));
    }

    public function testUpdateWithRemoveImageDeletesFile(): void
    {
        $product = $this->createProductWithImage();
        $previousImage = (string) $product->getImageFilename();

        $data = ProductData::fromProduct($product);
        $data->removeImage = true;
        $this->productService->update($product, $data);

        self::assertNull($product->getImageFilename());
        self::assertFileDoesNotExist($this->imagePath($previousImage));
    }

    public function testNewImageWinsOverRemoveImage(): void
    {
        $product = $this->createProductWithImage();

        $data = ProductData::fromProduct($product);
        $data->image = $this->upload();
        $data->removeImage = true;
        $this->productService->update($product, $data);

        self::assertNotNull($product->getImageFilename());
    }

    public function testUpdateWithoutImageKeepsCurrentOne(): void
    {
        $product = $this->createProductWithImage();
        $currentImage = (string) $product->getImageFilename();

        $data = ProductData::fromProduct($product);
        $data->name = 'Carnet ligné';
        $this->productService->update($product, $data);

        self::assertSame($currentImage, $product->getImageFilename());
        self::assertFileExists($this->imagePath($currentImage));
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

    private function createProductWithImage(): Product
    {
        $data = $this->createData('Carnet');
        $data->image = $this->upload();

        return $this->productService->create($data);
    }

    private function upload(): UploadedFile
    {
        return new UploadedFile($this->files->png(), 'photo.png', null, null, true);
    }

    private function imagePath(string $filename): string
    {
        return $this->imageDirectory.'/'.$filename;
    }

    private function createProduct(string $name): Product
    {
        return (new Product())->setName($name)->setPrice(990);
    }

    /**
     * Données valides, comme après la validation du formulaire.
     */
    private function createData(string $name): ProductData
    {
        $data = new ProductData();
        $data->name = $name;
        $data->category = (new Category())->setName('Mugs');
        $data->price = 990;

        return $data;
    }
}
