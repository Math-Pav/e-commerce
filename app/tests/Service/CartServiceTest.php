<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Product;
use App\Exception\InsufficientStockException;
use App\Repository\ProductRepository;
use App\Service\CartService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

final class CartServiceTest extends TestCase
{
    private CartService $cartService;

    private Product $mug;

    private Product $pen;

    protected function setUp(): void
    {
        $this->mug = $this->createProduct(1, 'Mug', price: 1290, stock: 5);
        $this->pen = $this->createProduct(2, 'Stylo', price: 150, stock: 100);
        $products = [$this->mug, $this->pen];

        // Une vraie session, mais stockée en mémoire au lieu d'un fichier
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        // Un faux repository : il renvoie nos produits (actifs, comme le vrai) sans toucher à la base
        $repository = $this->createStub(ProductRepository::class);
        $repository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => array_values(array_filter(
                $products,
                static fn (Product $product): bool => in_array($product->getId(), $criteria['id'], true)
                    && $product->isActive() === $criteria['isActive'],
            )),
        );

        $this->cartService = new CartService($requestStack, $repository);
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
        self::assertSame($this->mug, $lines[0]->product);
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
        self::assertSame($this->pen, $lines[0]->product);
    }

    public function testClearEmptiesCart(): void
    {
        $this->cartService->add($this->mug, 2);

        $this->cartService->clear();

        self::assertTrue($this->cartService->getCart()->isEmpty());
    }

    public function testUnsavedProductCannotBeAdded(): void
    {
        $unsaved = (new Product())->setName('Brouillon')->setPrice(100)->setStock(10);

        $this->expectException(\LogicException::class);

        $this->cartService->add($unsaved);
    }

    private function createProduct(int $id, string $name, int $price, int $stock): Product
    {
        $product = (new Product())
            ->setName($name)
            ->setPrice($price)
            ->setStock($stock);

        // L'id est normalement attribué par la base ; dans un test unitaire, on le force par réflexion
        (new \ReflectionProperty(Product::class, 'id'))->setValue($product, $id);

        return $product;
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
        $repository = $this->createMock(ProductRepository::class);
        $repository->expects(self::never())->method(self::anything());

        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requestStack = new RequestStack();
        $requestStack->push($request);

        self::assertSame(0, (new CartService($requestStack, $repository))->getItemCount());
    }

    public function testDeactivatedProductDisappearsFromCart(): void
    {
        $this->cartService->add($this->mug, 2);
        $this->cartService->add($this->pen, 3);

        $this->mug->setIsActive(false);

        $lines = $this->cartService->getCart()->lines;
        self::assertCount(1, $lines);
        self::assertSame($this->pen, $lines[0]->product);
        self::assertSame(3, $this->cartService->getItemCount());
    }
}
