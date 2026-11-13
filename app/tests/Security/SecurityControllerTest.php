<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityControllerTest extends WebTestCase
{
    public function testLoginWithValidCredentials(): void
    {
        $client = static::createClient();
        $client->request('GET', '/connexion');

        $client->submitForm('Se connecter', [
            '_username' => 'client@example.com',
            '_password' => 'password',
        ]);

        self::assertResponseRedirects('/produits');
        $client->followRedirect();
        self::assertSelectorTextContains('nav', 'Connecté en tant que Camille');
    }

    public function testLoginWithWrongPasswordShowsError(): void
    {
        $client = static::createClient();
        $client->request('GET', '/connexion');

        $client->submitForm('Se connecter', [
            '_username' => 'client@example.com',
            '_password' => 'mauvais-mot-de-passe',
        ]);

        self::assertResponseRedirects('/connexion');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Identifiants invalides');
        self::assertSelectorNotExists('a[href="/deconnexion"]');
    }

    public function testLoginWithInvalidCsrfTokenShowsFrenchError(): void
    {
        $client = static::createClient();
        $client->request('GET', '/connexion');

        // Message d'erreur par défaut du composant Security, traduit grâce au composant Translation
        $client->submitForm('Se connecter', [
            '_username' => 'client@example.com',
            '_password' => 'password',
            '_csrf_token' => 'jeton-invalide',
        ]);

        self::assertResponseRedirects('/connexion');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Jeton CSRF invalide.');
        self::assertSelectorNotExists('a[href="/deconnexion"]');
    }
}