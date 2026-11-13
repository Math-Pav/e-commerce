<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Model\ProfileData;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Modifications que le client fait lui-même sur son compte (espace « Mon compte »).
 */
final class AccountService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
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
}
