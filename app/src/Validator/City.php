<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Ville d'une adresse, partagée par l'adresse de livraison et le profil client.
 * La longueur maximale est celle des colonnes user.city et orders.shipping_city.
 * Une valeur vide est acceptée : c'est à chaque classe de dire, avec NotBlank, si le champ est obligatoire.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class City extends Compound
{
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Length(max: 100),
        ];
    }
}
