<?php

declare(strict_types=1);

namespace App\Api\Output;

/**
 * Le détail d'une commande du client (GET /api/v1/me/orders/{reference}). Dates au format ISO 8601, null si l'étape n'a pas eu lieu.
 */
final class OrderDetailOutput
{
    /**
     * @param list<OrderItemOutput> $items
     */
    public function __construct(
        public readonly string $reference,
        public readonly string $status,
        public readonly string $statusLabel,
        public readonly string $createdAt,
        public readonly ?string $paidAt,
        public readonly ?string $shippedAt,
        public readonly ?string $deliveredAt,
        public readonly ?string $cancelledAt,
        public readonly Price $subtotal,
        public readonly Price $discount,
        public readonly ?string $discountCode,
        public readonly Price $total,
        public readonly ShippingAddressOutput $shippingAddress,
        public readonly array $items,
    ) {
    }
}
