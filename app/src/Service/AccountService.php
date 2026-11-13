<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Model\ProfileData;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Modifications que le client fait lui-même sur son compte (espace « Mon compte »).
 */
final class AccountService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function updateProfile(User $user, ProfileData $data): void
    {
        $user
            ->setFirstName($data->firstName)
            ->setLastName($data->lastName)
            ->setPhone($data->phone)
            ->setAddress($data->address)
            ->setPostalCode($data->postalCode)
            ->setCity($data->city);

        $this->entityManager->flush();
    }

    /**
     * Enregistre le hash du nouveau mot de passe, jamais le mot de passe lui-même.
     * La vérification du mot de passe actuel est faite avant, par la validation du formulaire (UserPassword).
     */
    public function changePassword(User $user, string $plainPassword): void
    {
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));

        $this->entityManager->flush();
    }
}
