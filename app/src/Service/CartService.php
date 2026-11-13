<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Exception\InsufficientStockException;
use App\Model\Cart;
use App\Model\CartLine;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Panier stocké en session sous la forme [identifiant produit => quantité].
 */
final class CartService
{
    private const SESSION_KEY = 'cart';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ProductRepository $productRepository,
    ) {
    }

    /**
     * @throws InsufficientStockException
     */
    public function add(Product $product, int $quantity = 1): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('La quantité doit être au moins égale à 1.');
        }

        $quantities = $this->getQuantities();
        $newQuantity = ($quantities[$this->productId($product)] ?? 0) + $quantity;

        if ($newQuantity > $product->getStock()) {
            throw new InsufficientStockException($product);
        }

        $quantities[$this->productId($product)] = $newQuantity;
        $this->saveQuantities($quantities);
    }

    public function remove(Product $product): void
    {
        $quantities = $this->getQuantities();
        unset($quantities[$this->productId($product)]);
        $this->saveQuantities($quantities);
    }

    public function clear(): void
    {
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }

    /**
     * Calculé à partir du panier relu en base : un produit désactivé entre-temps n'est plus compté.
     */
    public function getItemCount(): int
    {
        return array_sum(array_map(
            static fn (CartLine $line): int => $line->quantity,
            $this->getCart()->lines,
        ));
    }

    public function getCart(): Cart
    {
        $quantities = $this->getQuantities();

        if ($quantities === []) {
            return new Cart([]);
        }

        // Une seule requête pour tous les produits ; les produits désactivés entre-temps sont ignorés
        $products = $this->productRepository->findBy([
            'id' => array_keys($quantities),
            'isActive' => true,
        ]);

        $lines = array_map(
            fn (Product $product): CartLine => new CartLine($product, $quantities[$this->productId($product)]),
            $products,
        );

        return new Cart($lines);
    }

    /**
     * L'identifiant sert de clé dans la session : seul un produit déjà enregistré en a un.
     */
    private function productId(Product $product): int
    {
        return $product->getId() ?? throw new \LogicException('Seul un produit enregistré peut être mis dans le panier.');
    }

    /**
     * @return array<int, int>
     */
    private function getQuantities(): array
    {
        return $this->requestStack->getSession()->get(self::SESSION_KEY, []);
    }

    /**
     * @param array<int, int> $quantities
     */
    private function saveQuantities(array $quantities): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $quantities);
    }
}
