<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\ApiToken;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Repository\ApiTokenRepository;
use App\Repository\UserRepository;
use App\Service\ApiTokenService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CustomerOrderControllerTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
    }

    public function testListOnlyContainsOwnOrders(): void
    {
        $this->createOrder('client@example.com', 'CMD-TEST-1', 2);
        $this->createOrder('admin@example.com', 'CMD-TEST-ADMIN', 1);
        $plainToken = $this->createToken('client@example.com');

        $body = $this->getJson('/api/v1/me/orders', $plainToken);

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['CMD-TEST-1'], array_column($body['data'], 'reference'));
        self::assertSame([
            'reference' => 'CMD-TEST-1',
            'status' => 'pending',
            'statusLabel' => 'En attente de paiement',
            'total' => ['amount' => 3000, 'currency' => 'EUR'],
            'itemCount' => 2,
        ], array_diff_key($body['data'][0], ['createdAt' => true]));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', $body['data'][0]['createdAt']);
    }

    public function testShowOwnOrder(): void
    {
        $this->createOrder('client@example.com', 'CMD-TEST-1', 2);

        $body = $this->getJson('/api/v1/me/orders/CMD-TEST-1', $this->createToken('client@example.com'));

        self::assertResponseIsSuccessful();
        $order = $body['data'];
        self::assertSame('CMD-TEST-1', $order['reference']);
        self::assertNull($order['paidAt']);
        self::assertSame(['amount' => 3000, 'currency' => 'EUR'], $order['subtotal']);
        self::assertSame(['amount' => 0, 'currency' => 'EUR'], $order['discount']);
        self::assertSame(['fullName' => 'Camille Martin', 'address' => '12 rue des Lilas', 'postalCode' => '69001', 'city' => 'Lyon'], $order['shippingAddress']);
        self::assertSame([[
            'productName' => 'Produit de test',
            'variantLabel' => null,
            'unitPrice' => ['amount' => 1500, 'currency' => 'EUR'],
            'quantity' => 2,
            'subtotal' => ['amount' => 3000, 'currency' => 'EUR'],
        ]], $order['items']);
    }

    public function testOrderOfAnotherCustomerIsNotFound(): void
    {
        $this->createOrder('admin@example.com', 'CMD-TEST-ADMIN', 1);

        $body = $this->getJson('/api/v1/me/orders/CMD-TEST-ADMIN', $this->createToken('client@example.com'));

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('La commande « CMD-TEST-ADMIN » n\'existe pas.', $body['detail']);
    }

    public function testLastUsedDateIsUpdated(): void
    {
        $plainToken = $this->createToken('client@example.com');

        $this->getJson('/api/v1/me/orders', $plainToken);

        $apiToken = static::getContainer()->get(ApiTokenRepository::class)->findOneBy(['tokenHash' => hash('sha256', $plainToken)]);
        self::assertNotNull($apiToken?->getLastUsedAt());
    }

    public function testMissingTokenIsUnauthorized(): void
    {
        $body = $this->getJson('/api/v1/me/orders');

        $this->assertUnauthorized($body, 'Authentification requise');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer realm="api"');
    }

    public function testUnknownTokenIsUnauthorized(): void
    {
        $body = $this->getJson('/api/v1/me/orders', str_repeat('a', 64));

        $this->assertUnauthorized($body, 'Jeton d\'accès invalide ou révoqué.');
        self::assertResponseHeaderSame('WWW-Authenticate', 'Bearer realm="api", error="invalid_token"');
    }

    public function testExpiredTokenIsUnauthorized(): void
    {
        $customer = $this->findUser('client@example.com');
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new ApiToken($customer, 'Ancien', hash('sha256', 'jeton-expire'), 'jeton-ex', new \DateTimeImmutable('-1 second')));
        $entityManager->flush();

        $body = $this->getJson('/api/v1/me/orders', 'jeton-expire');

        $this->assertUnauthorized($body, 'Jeton d\'accès expiré');
    }

    public function testRevokedTokenIsUnauthorized(): void
    {
        $plainToken = $this->createToken('client@example.com');
        $apiTokenService = static::getContainer()->get(ApiTokenService::class);
        $apiToken = $apiTokenService->findByPlainToken($plainToken);
        self::assertNotNull($apiToken);
        $apiTokenService->revoke($apiToken);

        $body = $this->getJson('/api/v1/me/orders', $plainToken);

        $this->assertUnauthorized($body, 'Jeton d\'accès invalide ou révoqué.');
    }

    public function testTokenInQueryStringIsIgnored(): void
    {
        // Une adresse est écrite dans les journaux du serveur : le jeton n'est accepté que dans l'en-tête
        $body = $this->getJson('/api/v1/me/orders?access_token='.$this->createToken('client@example.com'));

        $this->assertUnauthorized($body, 'Authentification requise');
    }

    public function testSiteSessionDoesNotAuthenticateTheApi(): void
    {
        // Vraie connexion par le formulaire (loginUser() connecterait aussi la requête suivante, quel que soit le pare-feu)
        $this->logInWithForm();

        $body = $this->getJson('/api/v1/me/orders');

        $this->assertUnauthorized($body, 'Authentification requise');
    }

    public function testApiDoesNotStartASession(): void
    {
        $this->getJson('/api/v1/me/orders', $this->createToken('client@example.com'));

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->client->getResponse()->headers->getCookies());
    }

    public function testSitePagesStillUseTheSession(): void
    {
        $this->logInWithForm();
        $this->getJson('/api/v1/me/orders', $this->createToken('client@example.com'));

        $this->client->request('GET', '/mes-commandes');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mes commandes');
    }

    private function logInWithForm(): void
    {
        $this->client->request('GET', '/connexion');
        $this->client->submitForm('Se connecter', ['_username' => 'client@example.com', '_password' => 'password']);
        self::assertResponseRedirects();
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertUnauthorized(array $body, string $detail): void
    {
        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame(['type' => 'about:blank', 'title' => 'Unauthorized', 'status' => 401], array_diff_key($body, ['detail' => true]));
        self::assertStringContainsString($detail, $body['detail']);
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(string $url, ?string $plainToken = null): array
    {
        $server = null === $plainToken ? [] : ['HTTP_AUTHORIZATION' => 'Bearer '.$plainToken];
        $this->client->request('GET', $url, server: $server);

        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function createToken(string $email): string
    {
        return static::getContainer()->get(ApiTokenService::class)->create($this->findUser($email), 'Mon téléphone');
    }

    private function createOrder(string $email, string $reference, int $quantity): void
    {
        $order = (new Order())
            ->setReference($reference)
            ->setCustomer($this->findUser($email))
            ->setShippingFullName('Camille Martin')
            ->setShippingAddress('12 rue des Lilas')
            ->setShippingPostalCode('69001')
            ->setShippingCity('Lyon');
        $order->addItem((new OrderItem())
            ->setProductName('Produit de test')
            ->setUnitPrice(1500)
            ->setQuantity($quantity));
        $order->recalculateTotal();

        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($order);
        $entityManager->flush();
    }

    private function findUser(string $email): User
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);

        return $user;
    }
}
