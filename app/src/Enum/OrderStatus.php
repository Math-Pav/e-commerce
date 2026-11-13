<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * États possibles d'une commande. La valeur (à droite) est celle stockée en base.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * Statuts qui comptent dans le chiffre d'affaires : la commande a été payée.
     * Une commande en attente n'a rien rapporté, une commande annulée ne rapportera rien.
     *
     * @return list<self>
     */
    public static function revenueStatuses(): array
    {
        return [self::Paid, self::Shipped, self::Delivered];
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En attente de paiement',
            self::Paid => 'Payée',
            self::Shipped => 'Expédiée',
            self::Delivered => 'Livrée',
            self::Cancelled => 'Annulée',
        };
    }
}