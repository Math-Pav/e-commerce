<?php

declare(strict_types=1);

namespace App\Validator;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Constraints\Compound;

/**
 * Format d'un code postal français, partagé par l'adresse de livraison et le profil client.
 *
 * Une contrainte « Compound » regroupe plusieurs contraintes sous un seul nom réutilisable.
 * Une valeur vide est acceptée : c'est à chaque classe de dire, avec NotBlank, si le champ est obligatoire.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class PostalCode extends Compound
{
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\Regex(pattern: '/^\d{5}$/', message: 'Le code postal doit contenir 5 chiffres.'),
        ];
    }
}
