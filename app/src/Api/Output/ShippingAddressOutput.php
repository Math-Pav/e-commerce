<?php

declare(strict_types=1);

namespace App\Api\Output;

final class ShippingAddressOutput
{
    public function __construct(
        public readonly string $fullName,
        public readonly string $address,
        public readonly string $postalCode,
        public readonly string $city,
    ) {
    }
}
