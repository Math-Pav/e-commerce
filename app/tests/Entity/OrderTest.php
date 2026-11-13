<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Order;
use App\Enum\OrderStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OrderTest extends TestCase
{
    public function testMarkAsShippedFromPaid(): void
    {
        $order = $this->createOrderWithStatus(OrderStatus::Paid);
        $shippedAt = new \DateTimeImmutable('2026-11-13 10:00');

        $order->markAsShipped($shippedAt);

        self::assertSame(OrderStatus::Shipped, $order->getStatus());
        self::assertSame($shippedAt, $order->getShippedAt());
    }

    public function testMarkAsDeliveredFromShipped(): void
    {
        $order = $this->createOrderWithStatus(OrderStatus::Shipped);
        $deliveredAt = new \DateTimeImmutable('2026-11-15 14:00');

        $order->markAsDelivered($deliveredAt);

        self::assertSame(OrderStatus::Delivered, $order->getStatus());
        self::assertSame($deliveredAt, $order->getDeliveredAt());
    }

    public function testCancelFromPending(): void
    {
        $order = new Order();
        $cancelledAt = new \DateTimeImmutable('2026-11-13 10:00');

        $order->cancel($cancelledAt);

        self::assertSame(OrderStatus::Cancelled, $order->getStatus());
        self::assertSame($cancelledAt, $order->getCancelledAt());
    }

    #[DataProvider('statusesThatCannotBeShipped')]
    public function testCannotShipUnlessPaid(OrderStatus $status): void
    {
        $order = $this->createOrderWithStatus($status);

        $dateBefore = $order->getShippedAt();

        try {
            $order->markAsShipped(new \DateTimeImmutable());
            self::fail('Une \DomainException était attendue.');
        } catch (\DomainException) {
            self::assertSame($status, $order->getStatus(), 'Le statut ne doit pas changer après un refus.');
            self::assertSame($dateBefore, $order->getShippedAt(), 'La date ne doit pas changer après un refus.');
        }
    }

    #[DataProvider('statusesThatCannotBeDelivered')]
    public function testCannotDeliverUnlessShipped(OrderStatus $status): void
    {
        $order = $this->createOrderWithStatus($status);

        $dateBefore = $order->getDeliveredAt();

        try {
            $order->markAsDelivered(new \DateTimeImmutable());
            self::fail('Une \DomainException était attendue.');
        } catch (\DomainException) {
            self::assertSame($status, $order->getStatus(), 'Le statut ne doit pas changer après un refus.');
            self::assertSame($dateBefore, $order->getDeliveredAt(), 'La date ne doit pas changer après un refus.');
        }
    }

    #[DataProvider('statusesThatCannotBeCancelled')]
    public function testCannotCancelUnlessPending(OrderStatus $status): void
    {
        $order = $this->createOrderWithStatus($status);

        $dateBefore = $order->getCancelledAt();

        try {
            $order->cancel(new \DateTimeImmutable());
            self::fail('Une \DomainException était attendue.');
        } catch (\DomainException) {
            self::assertSame($status, $order->getStatus(), 'Le statut ne doit pas changer après un refus.');
            self::assertSame($dateBefore, $order->getCancelledAt(), 'La date ne doit pas changer après un refus.');
        }
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function statusesThatCannotBeShipped(): iterable
    {
        return self::statusesExcept(OrderStatus::Paid);
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function statusesThatCannotBeDelivered(): iterable
    {
        return self::statusesExcept(OrderStatus::Shipped);
    }

    /**
     * @return iterable<string, array{OrderStatus}>
     */
    public static function statusesThatCannotBeCancelled(): iterable
    {
        return self::statusesExcept(OrderStatus::Pending);
    }

    /**
     * Tous les statuts sauf celui depuis lequel la transition est autorisée.
     *
     * @return iterable<string, array{OrderStatus}>
     */
    private static function statusesExcept(OrderStatus $allowed): iterable
    {
        foreach (OrderStatus::cases() as $status) {
            if ($status !== $allowed) {
                yield $status->value => [$status];
            }
        }
    }

    /**
     * Amène une commande neuve dans le statut voulu en passant par les transitions autorisées.
     */
    private function createOrderWithStatus(OrderStatus $status): Order
    {
        $order = new Order();
        $now = new \DateTimeImmutable();

        match ($status) {
            OrderStatus::Pending => null,
            OrderStatus::Paid => $order->markAsPaid($now),
            OrderStatus::Shipped => $this->ship($order, $now),
            OrderStatus::Delivered => $this->deliver($order, $now),
            OrderStatus::Cancelled => $order->cancel($now),
        };

        return $order;
    }

    private function ship(Order $order, \DateTimeImmutable $now): void
    {
        $order->markAsPaid($now);
        $order->markAsShipped($now);
    }

    private function deliver(Order $order, \DateTimeImmutable $now): void
    {
        $this->ship($order, $now);
        $order->markAsDelivered($now);
    }
}
