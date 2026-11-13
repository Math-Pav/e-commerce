<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ApiTokenServiceTest extends KernelTestCase
{
    private ApiTokenService $apiTokenService;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->apiTokenService = static::getContainer()->get(ApiTokenService::class);
    }

    public function testOnlyTheHashOfTheTokenIsStored(): void
    {
        $plainToken = $this->apiTokenService->create($this->findCustomer(), 'Mon téléphone');

        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plainToken);
        $apiToken = $this->apiTokenService->findByPlainToken($plainToken);
        self::assertNotNull($apiToken);
        self::assertSame(hash('sha256', $plainToken), $apiToken->getTokenHash());
        self::assertSame(substr($plainToken, 0, 8), $apiToken->getTokenPrefix());
        self::assertSame('Mon téléphone', $apiToken->getName());
        self::assertNull($apiToken->getLastUsedAt());
    }

    public function testTokensAreRandom(): void
    {
        $customer = $this->findCustomer();

        self::assertNotSame($this->apiTokenService->create($customer, 'A'), $this->apiTokenService->create($customer, 'B'));
    }

    public function testTokenExpiresAfterNinetyDays(): void
    {
        $apiToken = $this->apiTokenService->findByPlainToken($this->apiTokenService->create($this->findCustomer(), 'Mon téléphone'));
        self::assertNotNull($apiToken);

        $lifetimeInDays = $apiToken->getCreatedAt()->diff($apiToken->getExpiresAt())->days;

        self::assertSame(90, $lifetimeInDays);
    }

    public function testUnknownTokenIsNotFound(): void
    {
        self::assertNull($this->apiTokenService->findByPlainToken(str_repeat('0', 64)));
    }

    public function testSixthActiveTokenIsRefused(): void
    {
        $customer = $this->findCustomer();
        for ($i = 1; $i <= ApiTokenService::MAX_ACTIVE_TOKENS; ++$i) {
            $this->apiTokenService->create($customer, 'Jeton '.$i);
        }

        $this->expectException(\DomainException::class);
        $this->apiTokenService->create($customer, 'Jeton de trop');
    }

    public function testExpiredTokensDoNotCountTowardsTheLimit(): void
    {
        $customer = $this->findCustomer();
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        for ($i = 1; $i <= ApiTokenService::MAX_ACTIVE_TOKENS; ++$i) {
            $entityManager->persist(new ApiToken($customer, 'Ancien '.$i, hash('sha256', 'ancien'.$i), 'ancien00', new \DateTimeImmutable('-1 day')));
        }
        $entityManager->flush();

        $plainToken = $this->apiTokenService->create($customer, 'Nouveau');

        self::assertNotNull($this->apiTokenService->findByPlainToken($plainToken));
    }

    public function testRevokedTokenIsDeleted(): void
    {
        $plainToken = $this->apiTokenService->create($this->findCustomer(), 'Mon téléphone');
        $apiToken = $this->apiTokenService->findByPlainToken($plainToken);
        self::assertNotNull($apiToken);

        $this->apiTokenService->revoke($apiToken);

        self::assertNull($this->apiTokenService->findByPlainToken($plainToken));
        self::assertSame(0, static::getContainer()->get(ApiTokenRepository::class)->count([]));
    }

    private function findCustomer(): User
    {
        $customer = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'client@example.com']);
        self::assertNotNull($customer);

        return $customer;
    }
}
