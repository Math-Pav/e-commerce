<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Repository\UserRepository;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderControllerTest extends WebTestCase
{
    public function testCheckoutRequiresLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/commande');

        self::assertResponseRedirects('/connexion');
    }

    public function testCheckoutWithEmptyCartRedirectsToCart(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $client->request('GET', '/commande');

        self::assertResponseRedirects('/panier');
    }

    public function testPlaceOrder(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->addToCart($client, 'mug-noir-mat', 2);

        $this->submitCheckout($client, '69001');

        self::assertResponseRedirects();
        self::assertStringStartsWith('/mes-commandes/CMD-', (string) $client->getResponse()->headers->get('Location'));

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'En attente de paiement');
        self::assertSelectorTextContains('table', 'Mug noir mat');
        self::assertSelectorTextContains('body', 'Total : 29,80 €');

        $client->request('GET', '/panier');
        self::assertSelectorTextContains('body', 'Votre panier est vide.');
    }

    public function testInvalidPostalCodeShowsError(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->addToCart($client, 'mug-noir-mat', 1);

        $this->submitCheckout($client, 'abc');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le code postal doit contenir 5 chiffres.');
    }

    public function testCustomerCannotSeeAnotherCustomerOrder(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->addToCart($client, 'mug-noir-mat', 1);
        $this->submitCheckout($client, '69001');
        $orderUrl = (string) $client->getResponse()->headers->get('Location');

        $this->logIn($client, 'admin@example.com');
        $client->request('GET', $orderUrl);

        self::assertResponseStatusCodeSame(404);
    }

    private function logIn(KernelBrowser $client, string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        $client->loginUser($user);
    }

    private function addToCart(KernelBrowser $client, string $slug, int $quantity): void
    {
        $client->request('GET', '/produits/' . $slug);
        $client->submitForm('Ajouter au panier', ['quantity' => $quantity]);
    }

    private function submitCheckout(KernelBrowser $client, string $postalCode): void
    {
        $client->request('GET', '/commande');
        $client->submitForm('Valider la commande', [
            'shipping_address[address]' => '12 rue des Lilas',
            'shipping_address[postalCode]' => $postalCode,
            'shipping_address[city]' => 'Lyon',
        ]);
    }
}