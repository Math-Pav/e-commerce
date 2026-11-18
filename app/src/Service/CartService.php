<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ProductVariant;
use App\Entity\PromoCode;
use App\Exception\InsufficientStockException;
use App\Exception\InvalidPromoCodeException;
use App\Model\Cart;
use App\Model\CartLine;
use App\Repository\ProductVariantRepository;
use App\Repository\PromoCodeRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * Panier stocké en session sous la forme [identifiant de variante => quantité] (ADR 0017),
 * avec à part le code promo appliqué (son texte seulement, revérifié à chaque lecture du panier).
 */
final class CartService
{
    private const SESSION_KEY = 'cart_variants';

    // Ancien panier [identifiant produit => quantité], d'avant les variantes : vidé à la première lecture
    private const LEGACY_SESSION_KEY = 'cart';

    private const PROMO_CODE_SESSION_KEY = 'cart_promo_code';

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly ProductVariantRepository $variantRepository,
        private readonly PromoCodeRepository $promoCodeRepository,
        private readonly DiscountCalculator $discountCalculator,
    ) {
    }

    /**
     * @throws InsufficientStockException
     */
    public function add(ProductVariant $variant, int $quantity = 1): void
    {
        if ($quantity < 1) {
            throw new \InvalidArgumentException('La quantité doit être au moins égale à 1.');
        }

        $quantities = $this->getQuantities();
        $newQuantity = ($quantities[$this->variantId($variant)] ?? 0) + $quantity;

        if ($newQuantity > $variant->getStock()) {
            throw new InsufficientStockException($variant);
        }

        $quantities[$this->variantId($variant)] = $newQuantity;
        $this->saveQuantities($quantities);
    }

    public function remove(ProductVariant $variant): void
    {
        $quantities = $this->getQuantities();
        unset($quantities[$this->variantId($variant)]);
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
            ?? throw new InvalidPromoCodeException(sprintf('Le code promo « %s » n\'existe pas. Vérifiez son orthographe.', PromoCode::normalize($code)));

        $promoCode->assertApplicable(new \DateTimeImmutable(), (new Cart($this->loadLines()))->getSubtotal());

        $this->requestStack->getSession()->set(self::PROMO_CODE_SESSION_KEY, $promoCode->getCode());

        return $promoCode;
    }

    public function removePromoCode(): void
    {
        $this->requestStack->getSession()->remove(self::PROMO_CODE_SESSION_KEY);
    }

    /**
     * Calculé à partir du panier relu en base : une variante devenue indisponible n'est plus comptée.
     * Affiché sur chaque page : il ne modifie pas le panier et n'affiche aucun message (c'est le rôle de getCart()).
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
     * Réconciliation : un ancien panier, une variante devenue indisponible ou un code devenu inapplicable
     * (désactivé, expiré, minimum plus atteint…) sont retirés avec un message.
     */
    public function getCart(): Cart
    {
        $this->discardLegacyCart();
        $lines = $this->loadLines();
        $this->removeUnavailableVariants($lines);

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

        // Une seule requête pour toutes les variantes ; celles devenues indisponibles sont ignorées
        $variants = $this->variantRepository->findAvailableByIds(array_keys($quantities));

        return array_map(
            fn (ProductVariant $variant): CartLine => new CartLine($variant, $quantities[$this->variantId($variant)]),
            $variants,
        );
    }

    /**
     * Retire de la session les variantes absentes des lignes relues (supprimées, désactivées, produit désactivé).
     *
     * @param list<CartLine> $lines
     */
    private function removeUnavailableVariants(array $lines): void
    {
        $quantities = $this->getQuantities();
        $available = array_map(fn (CartLine $line): int => $this->variantId($line->variant), $lines);
        $unavailable = array_diff(array_keys($quantities), $available);

        if ([] === $unavailable) {
            return;
        }

        foreach ($unavailable as $variantId) {
            unset($quantities[$variantId]);
        }
        $this->saveQuantities($quantities);
        $this->addFlash('error', 1 === count($unavailable)
            ? 'Un article de votre panier n\'est plus disponible : il a été retiré.'
            : sprintf('%d articles de votre panier ne sont plus disponibles : ils ont été retirés.', count($unavailable)));
    }

    /**
     * Un panier enregistré avant les variantes contient des identifiants de produits : on ne peut pas
     * les lire comme des identifiants de variantes (ce seraient d'autres articles). Il est vidé une fois.
     */
    private function discardLegacyCart(): void
    {
        $session = $this->requestStack->getSession();

        if (!$session->has(self::LEGACY_SESSION_KEY)) {
            return;
        }

        $legacyCart = $session->remove(self::LEGACY_SESSION_KEY);
        if (is_array($legacyCart) && [] !== $legacyCart) {
            $this->addFlash('error', 'Notre catalogue a évolué : votre panier a été vidé. Merci d\'ajouter à nouveau vos articles.');
        }
    }

    private function addFlash(string $type, string $message): void
    {
        $session = $this->requestStack->getSession();

        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add($type, $message);
        }
    }

    /**
     * L'identifiant sert de clé dans la session : seule une variante déjà enregistrée en a un.
     */
    private function variantId(ProductVariant $variant): int
    {
        return $variant->getId() ?? throw new \LogicException('Seule une variante enregistrée peut être mise dans le panier.');
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
