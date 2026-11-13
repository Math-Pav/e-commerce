<?php

declare(strict_types=1);

namespace App\Twig;

use App\Service\CartService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonction Twig cart_item_count() : nombre d'articles dans le panier, affiché dans l'en-tête.
 */
final class CartExtension extends AbstractExtension
{
    public function __construct(private readonly CartService $cartService)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cart_item_count', $this->cartService->getItemCount(...)),
        ];
    }
}
