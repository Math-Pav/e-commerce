<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A-03 : pages d'erreur du site, en français et dans le gabarit du site.
 * Symfony n'utilise les templates d'erreur que sans le mode débogage : on crée le client avec debug à false,
 * comme en production.
 */
final class ErrorPageTest extends WebTestCase
{
    /**
     * Sans le mode débogage, Symfony ne vérifie jamais si son cache est à jour : un conteneur ou un template
     * compilé lors d'un lancement précédent servirait tel quel, même après une modification du code.
     * On supprime donc le conteneur « sans débogage » et les templates compilés avant ces tests.
     */
    public static function setUpBeforeClass(): void
    {
        $cacheDir = dirname(__DIR__, 2).'/var/cache/test';
        (new Filesystem())->remove([
            $cacheDir.'/App_KernelTestContainer.php',
            $cacheDir.'/App_KernelTestContainer.php.meta',
            $cacheDir.'/twig',
        ]);
    }

    public function testNotFoundPageIsInFrenchWithSiteLayout(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/produits/nexiste-pas');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Page introuvable');
        self::assertSelectorExists('header.site-header a[href="/"]');
        self::assertSelectorExists('main a[href="/produits"]');
        self::assertSelectorTextNotContains('body', 'Oops');
    }

    public function testUnknownUrlUsesNotFoundPage(): void
    {
        $client = static::createClient(['debug' => false]);

        $client->request('GET', '/adresse-qui-nexiste-pas');

        self::assertResponseStatusCodeSame(404);
        self::assertSelectorTextContains('h1', 'Page introuvable');
    }

    public function testForbiddenPageIsInFrench(): void
    {
        $client = static::createClient(['debug' => false]);
        $customer = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => 'client@example.com']);
        self::assertNotNull($customer);
        $client->loginUser($customer);

        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
        self::assertSelectorTextContains('h1', 'Accès refusé');
        self::assertSelectorTextNotContains('body', 'Oops');
    }

    public function testOtherErrorsUseGenericFrenchPage(): void
    {
        $client = static::createClient(['debug' => false]);

        // Méthode non autorisée (405) : aucune page dédiée, c'est la page générique qui s'affiche
        $client->request('DELETE', '/panier');

        self::assertResponseStatusCodeSame(405);
        self::assertSelectorTextContains('h1', 'Une erreur est survenue');
        self::assertSelectorExists('header.site-header');
        self::assertSelectorTextNotContains('body', 'Oops');
    }
}
