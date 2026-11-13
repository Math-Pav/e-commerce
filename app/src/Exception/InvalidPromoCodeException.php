<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Levée quand un code promo ne peut pas (ou plus) être appliqué. Le message est destiné au client.
 */
final class InvalidPromoCodeException extends \DomainException
{
}
