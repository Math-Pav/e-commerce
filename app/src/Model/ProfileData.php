<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\User;
use App\Validator as AppAssert;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données du formulaire « Mon profil ».
 * Le formulaire remplit cet objet (même invalide) ; AccountService ne reporte sur User que des données validées.
 *
 * L'adresse est facultative dans le profil (aucun NotBlank), mais si elle est saisie,
 * elle suit les mêmes règles de format que l'adresse de livraison (contraintes de App\Validator).
 */
final class ProfileData
{
    #[Assert\NotBlank(message: 'Veuillez saisir votre prénom.')]
    #[Assert\Length(max: 50)]
    public string $firstName = '';

    #[Assert\NotBlank(message: 'Veuillez saisir votre nom.')]
    #[Assert\Length(max: 50)]
    public string $lastName = '';

    // Chiffres, espaces, points, tirets et un « + » initial : 06 12 34 56 78, +33 6 12 34 56 78…
    #[Assert\Regex(pattern: '/^\+?[0-9][0-9 .\-]{5,18}$/', message: "Le numéro de téléphone n'est pas valide.")]
    public ?string $phone = null;

    #[AppAssert\StreetAddress]
    public ?string $address = null;

    #[AppAssert\PostalCode]
    public ?string $postalCode = null;

    #[AppAssert\City]
    public ?string $city = null;

    /**
     * Pré-remplit le formulaire avec les valeurs actuelles du client.
     */
    public static function fromUser(User $user): self
    {
        $data = new self();
        $data->firstName = (string) $user->getFirstName();
        $data->lastName = (string) $user->getLastName();
        $data->phone = $user->getPhone();
        $data->address = $user->getAddress();
        $data->postalCode = $user->getPostalCode();
        $data->city = $user->getCity();

        return $data;
    }
}
