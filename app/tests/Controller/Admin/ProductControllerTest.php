<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductControllerTest extends WebTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/produits');

        self::assertResponseRedirects('/connexion');
    }

    public function testCustomerIsForbidden(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $client->request('GET', '/admin/produits');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCustomerDoesNotSeeAdminLink(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $client->request('GET', '/produits');

        self::assertSelectorNotExists('a[href="/admin/produits"]');
    }

    public function testAdminSeesAllProducts(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin/produits');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav a[href="/admin/produits"]');
        self::assertSelectorTextContains('table', 'Agenda 2026');
        self::assertSelectorTextContains('table', 'Désactivé');
        self::assertSelectorTextContains('table', 'Rupture');
    }

    private function logIn(KernelBrowser $client, string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        $client->loginUser($user);
    }
}