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
}
