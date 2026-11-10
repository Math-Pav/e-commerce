<?php

declare(strict_types=1);

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Filtre Twig "price" : convertit un prix en centimes au format français (1290 → "12,90 €").
 */
final class PriceExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('price', $this->formatPrice(...)),
        ];
    }

    public function formatPrice(int $cents): string
    {
        return number_format($cents / 100, 2, ',', ' ') . ' €';
    }
}