<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CartControllerTest extends WebTestCase
{
    public function testNewCartIsEmpty(): void
    {
        $client = static::createClient();

        $client->request('GET', '/panier');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Votre panier est vide.');
    }

    public function testAddProductToCart(): void
    {
        $client = static::createClient();

        $this->addToCart($client, 'mug-noir-mat', 2);

        self::assertResponseRedirects('/panier');
        $client->followRedirect();
        self::assertSelectorTextContains('.cart-lines', 'Mug noir mat');
        self::assertSelectorTextContains('.summary-total', '29,80 €');
    }

    public function testCannotAddMoreThanStock(): void
    {
        $client = static::createClient();

        // Le mug noir mat a 12 exemplaires en stock
        $this->addToCart($client, 'mug-noir-mat', 13);

        self::assertResponseRedirects('/produits/mug-noir-mat');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Stock insuffisant');
    }

    public function testRemoveProductFromCart(): void
    {
        $client = static::createClient();
        $this->addToCart($client, 'mug-noir-mat', 1);
        $client->followRedirect();

        $client->submitForm('Retirer');

        self::assertResponseRedirects('/panier');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Votre panier est vide.');
    }

    public function testAddWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $product = static::getContainer()->get(ProductRepository::class)->findOneBy(['slug' => 'mug-noir-mat']);
        self::assertNotNull($product);

        // Requête forgée à la main, sans passer par le formulaire : pas de jeton CSRF
        $client->request('POST', '/panier/ajouter/' . $product->getId(), ['quantity' => 1]);

        self::assertResponseStatusCodeSame(403);
    }

    private function addToCart(KernelBrowser $client, string $slug, int $quantity): void
    {
        $client->request('GET', '/produits/' . $slug);

        $client->submitForm('Ajouter au panier', [
            'quantity' => $quantity,
        ]);
    }
}