<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Rue et numéro d'une adresse, partagés par l'adresse de livraison et le profil client.
 * La longueur maximale est celle des colonnes user.address et orders.shipping_address.
 * Une valeur vide est acceptée : c'est à chaque classe de dire, avec NotBlank, si le champ est obligatoire.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class StreetAddress extends Compound
{
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Length(max: 255),
        ];
    }
}
