<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Entity\PromoCode;
use App\Exception\InsufficientStockException;
use App\Exception\InvalidPromoCodeException;
use App\Model\Cart;
use App\Model\CartLine;
use App\Repository\ProductRepository;
use App\Repository\PromoCodeRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Panier stocké en session sous la forme [identifiant produit => quantité],
 * avec à part le code promo appliqué (son texte seulement, revérifié à chaque lecture du panier).
 */
final class CartService
{
    private const SESSION_KEY = 'cart';

    private const PROMO_CODE_SESSION_KEY = 'cart_promo_code';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ProductRepository $productRepository,
        private readonly PromoCodeRepository $promoCodeRepository,
        private readonly DiscountCalculator $discountCalculator,
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
        $this->removePromoCode();
    }

    /**
     * Un seul code par panier : appliquer un code remplace le précédent.
     *
     * @throws InvalidPromoCodeException si le code est vide, inconnu ou inapplicable à ce panier
     */
    public function applyPromoCode(string $code): PromoCode
    {
        if ('' === PromoCode::normalize($code)) {
            throw new InvalidPromoCodeException('Veuillez saisir un code promo.');
        }

        $promoCode = $this->promoCodeRepository->findOneByCode($code)
            ?? throw new InvalidPromoCodeException(sprintf('Le code promo « %s » n\'existe pas.', PromoCode::normalize($code)));

        $promoCode->assertApplicable(new \DateTimeImmutable(), (new Cart($this->loadLines()))->getSubtotal());

        $this->requestStack->getSession()->set(self::PROMO_CODE_SESSION_KEY, $promoCode->getCode());

        return $promoCode;
    }

    public function removePromoCode(): void
    {
        $this->requestStack->getSession()->remove(self::PROMO_CODE_SESSION_KEY);
    }

    /**
     * Calculé à partir du panier relu en base : un produit désactivé entre-temps n'est plus compté.
     */
    public function getItemCount(): int
    {
        return array_sum(array_map(
            static fn (CartLine $line): int => $line->quantity,
            $this->loadLines(),
        ));
    }

    /**
     * Le panier relu en base, avec la remise du code promo s'il est toujours applicable.
     * Un code devenu inapplicable (désactivé, expiré, minimum plus atteint…) est retiré avec un message.
     */
    public function getCart(): Cart
    {
        $lines = $this->loadLines();
        $code = $this->requestStack->getSession()->get(self::PROMO_CODE_SESSION_KEY);

        if (!is_string($code) || [] === $lines) {
            return new Cart($lines);
        }

        $cart = new Cart($lines);

        try {
            $promoCode = $this->promoCodeRepository->findOneByCode($code)
                ?? throw new InvalidPromoCodeException(sprintf('Le code promo « %s » n\'existe plus.', $code));
            $promoCode->assertApplicable(new \DateTimeImmutable(), $cart->getSubtotal());
        } catch (InvalidPromoCodeException $exception) {
            $this->removePromoCode();
            $this->addFlash('error', $exception->getMessage().' Il a été retiré de votre panier.');

            return $cart;
        }

        return new Cart($lines, $promoCode, $this->discountCalculator->calculate($promoCode, $cart->getSubtotal()));
    }

    /**
     * Lignes du panier relues en base, sans le code promo.
     *
     * @return list<CartLine>
     */
    private function loadLines(): array
    {
        $quantities = $this->getQuantities();

        if ([] === $quantities) {
            return [];
        }

        // Une seule requête pour tous les produits ; les produits désactivés entre-temps sont ignorés
        $products = $this->productRepository->findBy([
            'id' => array_keys($quantities),
            'isActive' => true,
        ]);

        return array_map(
            fn (Product $product): CartLine => new CartLine($product, $quantities[$this->productId($product)]),
            $products,
        );
    }

    private function addFlash(string $type, string $message): void
    {
        $session = $this->requestStack->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
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
