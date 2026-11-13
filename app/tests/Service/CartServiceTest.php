<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Exception\InsufficientStockException;
use App\Repository\ProductVariantRepository;
use App\Repository\PromoCodeRepository;
use App\Service\CartService;
use App\Service\DiscountCalculator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CartServiceTest extends TestCase
{
    private CartService $cartService;

    private Session $session;

    private ProductVariant $mug;

    private ProductVariant $pen;

    // Deux variantes d'un même produit, la verte ayant son propre prix
    private ProductVariant $blueMug;

    private ProductVariant $greenMug;

    protected function setUp(): void
    {
        $this->mug = $this->createVariant(1, 'Mug', price: 1290, stock: 5);
        $this->pen = $this->createVariant(2, 'Stylo', price: 150, stock: 100);
        $this->blueMug = $this->createVariant(3, 'Mug émaillé', price: 1690, stock: 10, label: 'Bleu');
        $this->greenMug = $this->createVariant(4, 'Mug émaillé', price: 1690, stock: 3, label: 'Vert', variantPrice: 1890);
        $variants = [$this->mug, $this->pen, $this->blueMug, $this->greenMug];

        // Une vraie session, mais stockée en mémoire au lieu d'un fichier
        $this->session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($this->session);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        // Un faux repository : il renvoie nos variantes en vente (comme le vrai) sans toucher à la base
        $repository = $this->createStub(ProductVariantRepository::class);
        $repository->method('findAvailableByIds')->willReturnCallback(
            static fn (array $ids): array => array_values(array_filter(
                $variants,
                static fn (ProductVariant $variant): bool => in_array($variant->getId(), $ids, true) && $variant->isAvailable(),
            )),
        );

        $this->cartService = new CartService($requestStack, $repository, $this->createStub(PromoCodeRepository::class), new DiscountCalculator());
    }

    public function testNewCartIsEmpty(): void
    {
        $cart = $this->cartService->getCart();

        self::assertTrue($cart->isEmpty());
        self::assertSame(0, $cart->getTotal());
    }

    public function testAddProduct(): void
    {
        $this->cartService->add($this->mug, 2);

        $lines = $this->cartService->getCart()->lines;
        self::assertCount(1, $lines);
        self::assertSame($this->mug, $lines[0]->variant);
        self::assertSame(2, $lines[0]->quantity);
    }

    public function testAddingSameProductTwiceAddsQuantities(): void
    {
        $this->cartService->add($this->mug, 2);
        $this->cartService->add($this->mug, 1);

        self::assertSame(3, $this->cartService->getCart()->lines[0]->quantity);
    }

    public function testTotalIsSumOfSubtotals(): void
    {
        $this->cartService->add($this->mug, 2);
        $this->cartService->add($this->pen, 3);

        // (2 × 12,90 €) + (3 × 1,50 €) = 25,80 € + 4,50 € = 30,30 €
        self::assertSame(3030, $this->cartService->getCart()->getTotal());
    }

    public function testCannotAddMoreThanStock(): void
    {
        $this->expectException(InsufficientStockException::class);

        $this->cartService->add($this->mug, 6);
    }

    public function testCannotExceedStockInSeveralAdditions(): void
    {
        $this->cartService->add($this->mug, 4);

        $this->expectException(InsufficientStockException::class);

        $this->cartService->add($this->mug, 2);
    }

    public function testCannotAddZeroQuantity(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->cartService->add($this->mug, 0);
    }

    public function testRemoveProduct(): void
    {
        $this->cartService->add($this->mug, 2);
        $this->cartService->add($this->pen, 1);

        $this->cartService->remove($this->mug);

        $lines = $this->cartService->getCart()->lines;
        self::assertCount(1, $lines);
        self::assertSame($this->pen, $lines[0]->variant);
    }

    public function testClearEmptiesCart(): void
    {
        $this->cartService->add($this->mug, 2);

        $this->cartService->clear();

        self::assertTrue($this->cartService->getCart()->isEmpty());
    }

    public function testUnsavedVariantCannotBeAdded(): void
    {
        $unsaved = (new ProductVariant())->setStock(10);
        (new Product())->setName('Brouillon')->setPrice(100)->addVariant($unsaved);

        $this->expectException(\LogicException::class);

        $this->cartService->add($unsaved);
    }

    private function createVariant(int $id, string $name, int $price, int $stock, ?string $label = null, ?int $variantPrice = null): ProductVariant
    {
        $variant = (new ProductVariant())
            ->setLabel($label)
            ->setPrice($variantPrice)
            ->setStock($stock);
        (new Product())->setName($name)->setPrice($price)->addVariant($variant);

        // L'id est normalement attribué par la base ; dans un test unitaire, on le force par réflexion
        (new \ReflectionProperty(ProductVariant::class, 'id'))->setValue($variant, $id);

        return $variant;
    }

    public function testItemCountSumsQuantities(): void
    {
        $this->cartService->add($this->mug, 2);
        $this->cartService->add($this->pen, 3);

        self::assertSame(5, $this->cartService->getItemCount());
    }

    public function testItemCountOfEmptyCartDoesNotQueryDatabase(): void
    {
        // Le compteur est affiché sur chaque page : un panier vide ne doit coûter aucune requête
        $repository = $this->createMock(ProductVariantRepository::class);
        $repository->expects(self::never())->method(self::anything());

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        self::assertSame(0, (new CartService($requestStack, $repository, $this->createStub(PromoCodeRepository::class), new DiscountCalculator()))->getItemCount());
    }

    public function testDeactivatedProductDisappearsFromCart(): void
    {
        $this->cartService->add($this->mug, 2);
        $this->cartService->add($this->pen, 3);

        $this->mug->setIsActive(false);

        $lines = $this->cartService->getCart()->lines;
        self::assertCount(1, $lines);
        self::assertSame($this->pen, $lines[0]->variant);
        self::assertSame(3, $this->cartService->getItemCount());
    }
}
