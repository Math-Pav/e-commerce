<?php

declare(strict_types=1);

namespace App\Model;

use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données du formulaire « Changer mon mot de passe ».
 *
 * Cet objet ne vit que le temps de la requête : il n'est ni enregistré en base ni placé en session.
 * Seul le hash du nouveau mot de passe est enregistré, par AccountService.
 */
final class ChangePasswordData
{
    // Sequentially : si le champ est vide, seul le message NotBlank s'affiche (UserPassword n'est pas évalué).
    // UserPassword compare la saisie au hash du mot de passe de l'utilisateur connecté.
    #[Assert\Sequentially([
        new Assert\NotBlank(message: 'Veuillez saisir votre mot de passe actuel.'),
        new UserPassword(message: 'Le mot de passe actuel est incorrect.'),
    ])]
    public ?string $currentPassword = null;

    #[Assert\NotBlank(message: 'Veuillez saisir un nouveau mot de passe.')]
    #[Assert\Length(
        min: 8,
        max: 4096,
        minMessage: 'Le mot de passe doit contenir au moins {{ limit }} caractères.',
    )]
    public ?string $newPassword = null;
}
