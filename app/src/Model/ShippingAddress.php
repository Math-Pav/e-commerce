<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\User;
use App\Validator as AppAssert;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Adresse de livraison saisie au moment de la commande.
 *
 * Les règles de format (longueurs, code postal) sont les contraintes partagées de App\Validator,
 * aussi utilisées par le profil client. NotBlank reste ici : à la commande, tous les champs sont obligatoires.
 */
final class ShippingAddress
{
    #[Assert\NotBlank(message: 'Veuillez saisir le nom du destinataire.')]
    #[Assert\Length(max: 100)]
    public string $fullName = '';

    #[Assert\NotBlank(message: "Veuillez saisir l'adresse.")]
    #[AppAssert\StreetAddress]
    public string $address = '';

    #[Assert\NotBlank(message: 'Veuillez saisir le code postal.')]
    #[AppAssert\PostalCode]
    public string $postalCode = '';

    #[Assert\NotBlank(message: 'Veuillez saisir la ville.')]
    #[AppAssert\City]
    public string $city = '';

    // Case « Enregistrer cette adresse dans mon profil » : traitée par OrderService après la commande
    public bool $saveToProfile = false;

    /**
     * Pré-remplit l'adresse de livraison avec le nom et l'adresse du profil du client.
     */
    public static function fromCustomer(User $customer): self
    {
        $address = new self();
        $address->fullName = trim(sprintf('%s %s', $customer->getFirstName(), $customer->getLastName()));
        $address->address = (string) $customer->getAddress();
        $address->postalCode = (string) $customer->getPostalCode();
        $address->city = (string) $customer->getCity();

        return $address;
    }
}