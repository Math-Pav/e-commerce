<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Output\OrderDetailOutput;
use App\Api\Output\OrderItemOutput;
use App\Api\Output\OrderOutput;
use App\Api\Output\Price;
use App\Api\Output\ShippingAddressOutput;
use App\Entity\Order;
use App\Entity\OrderItem;

/**
 * Transforme les commandes en objets de sortie de l'API « mes commandes » (ADR 0021),
 * sur le même principe que CatalogOutputFactory (ADR 0020).
 */
final class OrderOutputFactory
{
    /**
     * @param list<Order> $orders
     *
     * @return list<OrderOutput>
     */
    public function orders(array $orders): array
    {
        return array_map($this->order(...), $orders);
    }

    public function order(Order $order): OrderOutput
    {
        $itemCount = 0;
        foreach ($order->getItems() as $item) {
            $itemCount += (int) $item->getQuantity();
        }

        return new OrderOutput(
            (string) $order->getReference(),
            $order->getStatus()->value,
            $order->getStatus()->label(),
            $order->getCreatedAt()->format(\DATE_ATOM),
            new Price((int) $order->getTotalAmount()),
            $itemCount,
        );
    }

    public function orderDetail(Order $order): OrderDetailOutput
    {
        return new OrderDetailOutput(
            (string) $order->getReference(),
            $order->getStatus()->value,
            $order->getStatus()->label(),
            $order->getCreatedAt()->format(\DATE_ATOM),
            $order->getPaidAt()?->format(\DATE_ATOM),
            $order->getShippedAt()?->format(\DATE_ATOM),
            $order->getDeliveredAt()?->format(\DATE_ATOM),
            $order->getCancelledAt()?->format(\DATE_ATOM),
            new Price((int) $order->getSubtotalAmount()),
            new Price($order->getDiscountAmount()),
            $order->getDiscountCode(),
            new Price((int) $order->getTotalAmount()),
            new ShippingAddressOutput(
                (string) $order->getShippingFullName(),
                (string) $order->getShippingAddress(),
                (string) $order->getShippingPostalCode(),
                (string) $order->getShippingCity(),
            ),
            array_map($this->orderItem(...), $order->getItems()->getValues()),
        );
    }

    private function orderItem(OrderItem $item): OrderItemOutput
    {
        return new OrderItemOutput(
            (string) $item->getProductName(),
            $item->getVariantLabel(),
            new Price((int) $item->getUnitPrice()),
            (int) $item->getQuantity(),
            new Price($item->getSubtotal()),
        );
    }
}
