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

    /**
     * A-04 : après 5 échecs, la connexion est bloquée pendant 15 minutes, même avec le bon mot de passe.
     * Le limiteur est stocké dans le cache applicatif, vidé avant chaque test (ClearCachePoolsExtension) :
     * ces échecs ne gênent pas les autres tests de connexion.
     */
    public function testLoginIsThrottledAfterFiveFailures(): void
    {
        $client = static::createClient();

        for ($attempt = 1; $attempt <= 5; ++$attempt) {
            $client->request('GET', '/connexion');
            $client->submitForm('Se connecter', [
                '_username' => 'client@example.com',
                '_password' => 'mauvais-mot-de-passe',
            ]);
        }

        $client->request('GET', '/connexion');
        $client->submitForm('Se connecter', [
            '_username' => 'client@example.com',
            '_password' => 'password',
        ]);

        self::assertResponseRedirects('/connexion');
        $crawler = $client->followRedirect();
        // Symfony arrondit la durée restante à la minute supérieure : 15 ou 16 selon l'instant du test
        self::assertMatchesRegularExpression(
            '/Trop de tentatives de connexion échouées, veuillez réessayer dans 1[56] minutes\./',
            $crawler->filter('body')->text(),
        );
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
