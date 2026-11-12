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

    public function testPayOrder(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->addToCart($client, 'mug-noir-mat', 2);
        $this->submitCheckout($client, '69001');
        $client->followRedirect();

        $client->submitForm('Payer la commande');

        self::assertResponseRedirects();
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Statut : Payée');
        self::assertSelectorNotExists('form[action$="/payer"]');
    }

    public function testDeclinedPaymentKeepsOrderPending(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        // 8 × 12,90 € = 103,20 € : au-dessus du plafond du fournisseur simulé
        $this->addToCart($client, 'mug-blanc-classique', 8);
        $this->submitCheckout($client, '69001');
        $client->followRedirect();

        $client->submitForm('Payer la commande');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Le paiement a été refusé');
        self::assertSelectorTextContains('body', 'Statut : En attente de paiement');
    }

    public function testOrderHistoryShowsOnlyOwnOrders(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->addToCart($client, 'mug-noir-mat', 1);
        $this->submitCheckout($client, '69001');

        $client->request('GET', '/mes-commandes');
        self::assertSelectorTextContains('table', 'CMD-');

        $this->logIn($client, 'admin@example.com');
        $client->request('GET', '/mes-commandes');
        self::assertSelectorTextContains('body', "Vous n'avez pas encore passé de commande.");
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