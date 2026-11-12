<?php

declare(strict_types=1);

namespace App\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Adresse de livraison saisie au moment de la commande.
 */
final class ShippingAddress
{
    #[Assert\NotBlank(message: 'Veuillez saisir le nom du destinataire.')]
    #[Assert\Length(max: 100)]
    public string $fullName = '';

    #[Assert\NotBlank(message: "Veuillez saisir l'adresse.")]
    #[Assert\Length(max: 255)]
    public string $address = '';

    #[Assert\NotBlank(message: 'Veuillez saisir le code postal.')]
    #[Assert\Regex(pattern: '/^\d{5}$/', message: 'Le code postal doit contenir 5 chiffres.')]
    public string $postalCode = '';

    #[Assert\NotBlank(message: 'Veuillez saisir la ville.')]
    #[Assert\Length(max: 100)]
    public string $city = '';
}