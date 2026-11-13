<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ApiToken;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ApiTokenControllerTest extends WebTestCase
{
    public function testPageRequiresLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/mon-compte/jetons-api');

        self::assertResponseRedirects('/connexion');
    }

    public function testAccountPageLinksToTokens(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $client->request('GET', '/mon-compte');

        $client->clickLink('Gérer mes jetons d\'accès');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mes jetons d\'accès à l\'API');
        self::assertSelectorTextContains('main', 'Vous n\'avez aucun jeton.');
    }

    public function testCreatedTokenIsShownOnlyOnce(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $client->request('GET', '/mon-compte/jetons-api');

        $client->submitForm('Créer le jeton', ['api_token[name]' => 'Mon téléphone']);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $client->getResponse()->headers->get('Cache-Control'));
        $plainToken = (string) $client->getCrawler()->filter('#new-api-token')->attr('value');
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $plainToken);
        self::assertNotNull(static::getContainer()->get(ApiTokenService::class)->findByPlainToken($plainToken));
        self::assertSelectorTextContains('tbody', 'Mon téléphone');
        self::assertSelectorTextContains('tbody', substr($plainToken, 0, 8).'…');

        $client->request('GET', '/mon-compte/jetons-api');

        self::assertSelectorNotExists('#new-api-token');
        self::assertStringNotContainsString($plainToken, (string) $client->getResponse()->getContent());
        self::assertSelectorTextContains('tbody', 'Mon téléphone');
    }

    public function testNameIsRequired(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $client->request('GET', '/mon-compte/jetons-api');

        $client->submitForm('Créer le jeton', ['api_token[name]' => '']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorNotExists('#new-api-token');
        self::assertSame(0, static::getContainer()->get(ApiTokenRepository::class)->count([]));
    }

    public function testSixthActiveTokenIsRefused(): void
    {
        $client = static::createClient();
        $customer = $this->logIn($client, 'client@example.com');
        $apiTokenService = static::getContainer()->get(ApiTokenService::class);
        for ($i = 1; $i <= ApiTokenService::MAX_ACTIVE_TOKENS; ++$i) {
            $apiTokenService->create($customer, 'Jeton '.$i);
        }
        $client->request('GET', '/mon-compte/jetons-api');

        $client->submitForm('Créer le jeton', ['api_token[name]' => 'Jeton de trop']);

        self::assertResponseRedirects('/mon-compte/jetons-api');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Vous avez déjà 5 jetons actifs');
        self::assertSame(5, static::getContainer()->get(ApiTokenRepository::class)->count([]));
    }

    public function testRevokeToken(): void
    {
        $client = static::createClient();
        $customer = $this->logIn($client, 'client@example.com');
        static::getContainer()->get(ApiTokenService::class)->create($customer, 'Mon téléphone');
        $client->request('GET', '/mon-compte/jetons-api');

        $client->submitForm('Révoquer le jeton « Mon téléphone »');

        self::assertResponseRedirects('/mon-compte/jetons-api');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'Le jeton « Mon téléphone » a été révoqué.');
        self::assertSame(0, static::getContainer()->get(ApiTokenRepository::class)->count([]));
    }

    public function testRevokeRequiresValidCsrfToken(): void
    {
        $client = static::createClient();
        $customer = $this->logIn($client, 'client@example.com');
        $apiToken = $this->createToken($customer);

        $client->request('POST', sprintf('/mon-compte/jetons-api/%d/revoquer', $apiToken->getId()), ['_token' => 'invalide']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(1, static::getContainer()->get(ApiTokenRepository::class)->count([]));
    }

    public function testCannotRevokeTokenOfAnotherCustomer(): void
    {
        $client = static::createClient();
        $customer = $this->logIn($client, 'client@example.com');
        static::getContainer()->get(ApiTokenService::class)->create($customer, 'Mon téléphone');
        $adminToken = $this->createToken($this->findUser('admin@example.com'));
        $crawler = $client->request('GET', '/mon-compte/jetons-api');
        $csrfToken = (string) $crawler->filter('form[action$="/revoquer"] input[name="_token"]')->attr('value');

        $client->request('POST', sprintf('/mon-compte/jetons-api/%d/revoquer', $adminToken->getId()), ['_token' => $csrfToken]);

        self::assertResponseStatusCodeSame(404);
        self::assertSame(2, static::getContainer()->get(ApiTokenRepository::class)->count([]));
    }

    public function testExpiredTokenIsMarked(): void
    {
        $client = static::createClient();
        $customer = $this->logIn($client, 'client@example.com');
        $this->createToken($customer, new \DateTimeImmutable('-1 day'));

        $client->request('GET', '/mon-compte/jetons-api');

        self::assertSelectorTextContains('tbody .badge--danger', 'Expiré');
    }

    private function createToken(User $customer, \DateTimeImmutable $expiresAt = new \DateTimeImmutable('+90 days')): ApiToken
    {
        $apiToken = new ApiToken($customer, 'Mon téléphone', hash('sha256', 'jeton-de-test'), 'jeton-de', $expiresAt);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($apiToken);
        $entityManager->flush();

        return $apiToken;
    }

    private function logIn(KernelBrowser $client, string $email): User
    {
        $user = $this->findUser($email);
        $client->loginUser($user);

        return $user;
    }

    private function findUser(string $email): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return $user;
    }
}
