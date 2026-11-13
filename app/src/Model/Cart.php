<?php

declare(strict_types=1);

namespace App\Model;

final readonly class Cart
{
    /**
     * @param list<CartLine> $lines
     */
    public function __construct(
        public array $lines,
    ) {
    }

    public function getTotal(): int
    {
        return array_sum(array_map(
            static fn (CartLine $line): int => $line->getSubtotal(),
            $this->lines,
        ));
    }

    public function isEmpty(): bool
    {
        return [] === $this->lines;
    }
}
