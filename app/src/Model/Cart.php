<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\PromoCode;

final readonly class Cart
{
    /**
     * @param list<CartLine> $lines
     * @param int            $discount remise du code promo en centimes, calculée par DiscountCalculator
     */
    public function __construct(
        public array $lines,
        public ?PromoCode $promoCode = null,
        public int $discount = 0,
    ) {
    }

    public function getSubtotal(): int
    {
        return array_sum(array_map(
            static fn (CartLine $line): int => $line->getSubtotal(),
            $this->lines,
        ));
    }

    /**
     * Montant à payer : sous-total moins la remise.
     */
    public function getTotal(): int
    {
        return $this->getSubtotal() - $this->discount;
    }

    public function isEmpty(): bool
    {
        return [] === $this->lines;
    }
}
