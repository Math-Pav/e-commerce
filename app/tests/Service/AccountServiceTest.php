<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Model\ProfileData;
use App\Repository\UserRepository;
use App\Service\AccountService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class AccountServiceTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    private AccountService $accountService;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->accountService = $container->get(AccountService::class);
    }

    public function testProfileDataIsPrefilledFromUser(): void
    {
        $user = $this->findCustomer()->setPhone('0612345678')->setCity('Lyon');

        $data = ProfileData::fromUser($user);

        self::assertSame('Camille', $data->firstName);
        self::assertSame('Martin', $data->lastName);
        self::assertSame('0612345678', $data->phone);
        self::assertNull($data->address);
        self::assertSame('Lyon', $data->city);
    }

    public function testUpdateProfile(): void
    {
        $user = $this->findCustomer();
        $data = ProfileData::fromUser($user);
        $data->lastName = 'Martin-Leroy';
        $data->address = '3 place Bellecour';
        $data->postalCode = '69002';
        $data->city = 'Lyon';

        $this->accountService->updateProfile($user, $data);

        $this->entityManager->clear();
        $saved = $this->findCustomer();
        self::assertSame('Martin-Leroy', $saved->getLastName());
        self::assertSame('3 place Bellecour', $saved->getAddress());
        self::assertSame('69002', $saved->getPostalCode());
        self::assertSame('Lyon', $saved->getCity());
        self::assertNull($saved->getPhone());
    }

    private function findCustomer(): User
    {
        return static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'client@example.com']);
    }
}
