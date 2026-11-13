<?php

declare(strict_types=1);

namespace App\Model;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données du formulaire de création d'un jeton d'accès à l'API.
 */
final class ApiTokenData
{
    #[Assert\NotBlank(message: 'Donnez un nom à ce jeton pour le reconnaître.')]
    #[Assert\Length(max: 50)]
    public string $name = '';
}
