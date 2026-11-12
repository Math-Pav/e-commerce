<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductControllerTest extends WebTestCase
{
    public function testCatalogueListsOnlyActiveProducts(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le catalogue');
        self::assertAnySelectorTextContains('li', 'Mug noir mat');
        self::assertStringNotContainsString('Agenda 2026', (string) $client->getResponse()->getContent());
    }

    public function testHomePageShowsCatalogue(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le catalogue');
    }

    public function testCatalogueCanBeFilteredByCategory(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits?categorie=carnets');

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('li', 'Carnet A5 ligné');
        self::assertStringNotContainsString('Mug noir mat', (string) $client->getResponse()->getContent());
    }

    public function testProductPageDisplaysProduct(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits/mug-noir-mat');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mug noir mat');
        self::assertSelectorTextContains('body', '14,90 €');
        self::assertSelectorTextContains('body', 'En stock');
    }

    public function testOutOfStockProductIsFlagged(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits/mug-isotherme');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Rupture de stock');
    }

    #[DataProvider('unavailableSlugProvider')]
    public function testUnavailableProductReturns404(string $slug): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits/' . $slug);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unavailableSlugProvider(): iterable
    {
        yield 'produit désactivé' => ['agenda-2026'];
        yield 'produit inexistant' => ['nimporte-quoi'];
    }
}