<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Model\Cart;
use App\Model\CartLine;
use App\Model\ShippingAddress;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderControllerTest extends WebTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/commandes');

        self::assertResponseRedirects('/connexion');
    }

    public function testCustomerIsForbidden(): void
    {
        $client = static::createClient();
        $reference = $this->createOrder(paid: true);
        $this->logIn($client, 'client@example.com');

        $client->request('GET', '/admin/commandes');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/commandes/' . $reference);
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', sprintf('/admin/commandes/%s/expedier', $reference));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesAllOrders(): void
    {
        $client = static::createClient();
        $pending = $this->createOrder(paid: false);
        $paid = $this->createOrder(paid: true);
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin/commandes');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav a[href="/admin/commandes"][aria-current="page"]');
        self::assertSelectorTextContains('table', $pending);
        self::assertSelectorTextContains('table', $paid);
        self::assertSelectorTextContains('table', 'Camille Martin');
        self::assertSelectorTextContains('table', 'Statut : En attente de paiement');
        self::assertSelectorTextContains('table', 'Statut : Payée');
    }

    public function testAdminFiltersOrdersByStatus(): void
    {
        $client = static::createClient();
        $pending = $this->createOrder(paid: false);
        $paid = $this->createOrder(paid: true);
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin/commandes?statut=paid');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', $paid);
        self::assertSelectorTextNotContains('table', $pending);
        self::assertSelectorTextContains('.chips [aria-current="page"]', 'Payée');
    }

    public function testUnknownStatusShowsAllOrders(): void
    {
        $client = static::createClient();
        $pending = $this->createOrder(paid: false);
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin/commandes?statut=inconnu');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('table', $pending);
    }

    public function testAdminShipsPaidOrder(): void
    {
        $client = static::createClient();
        $reference = $this->createOrder(paid: true);
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin/commandes/' . $reference);
        $client->submitForm('Marquer comme expédiée');

        self::assertResponseRedirects('/admin/commandes/' . $reference);
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--success', sprintf('La commande %s est expédiée.', $reference));
        self::assertSelectorTextContains('.order-header', 'Statut : Expédiée');
        self::assertSame(OrderStatus::Shipped, $this->findOrder($reference)->getStatus());
    }

    public function testAdminCannotShipPendingOrder(): void
    {
        $client = static::createClient();
        $reference = $this->createOrder(paid: false);
        $this->logIn($client, 'admin@example.com');

        // Le bouton n'est pas affiché pour une commande en attente : on envoie la requête à la main,
        // avec un jeton CSRF valide récupéré sur la page (celui du formulaire d'annulation)
        $crawler = $client->request('GET', '/admin/commandes/' . $reference);
        self::assertSelectorNotExists('form[action$="/expedier"]');
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', sprintf('/admin/commandes/%s/expedier', $reference), ['_token' => $token]);

        self::assertResponseRedirects('/admin/commandes/' . $reference);
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Seule une commande payée peut être expédiée.');
        self::assertSame(OrderStatus::Pending, $this->findOrder($reference)->getStatus());
    }

    public function testAdminCancelsPendingOrderAndRestoresStock(): void
    {
        $client = static::createClient();
        $reference = $this->createOrder(paid: false); // 2 mugs noirs : stock 12 → 10
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin/commandes/' . $reference);
        $client->submitForm('Annuler la commande');

        self::assertResponseRedirects('/admin/commandes/' . $reference);
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'annulée');
        self::assertSelectorTextContains('.order-header', 'Statut : Annulée');
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertSame(12, static::getContainer()->get(ProductRepository::class)->findOneBy(['slug' => 'mug-noir-mat'])->getStock());
    }

    public function testActionWithoutValidCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $reference = $this->createOrder(paid: true);
        $this->logIn($client, 'admin@example.com');

        $client->request('POST', sprintf('/admin/commandes/%s/expedier', $reference), ['_token' => 'invalide']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::Paid, $this->findOrder($reference)->getStatus());
    }

    public function testUnknownOrderReturns404(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin/commandes/CMD-INCONNUE');

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * Crée une commande de 2 mugs noirs pour le client de démonstration, en attente de paiement ou payée.
     */
    private function createOrder(bool $paid): string
    {
        $container = static::getContainer();
        $address = new ShippingAddress();
        $address->fullName = 'Camille Martin';
        $address->address = '12 rue des Lilas';
        $address->postalCode = '69001';
        $address->city = 'Lyon';

        $order = $container->get(OrderService::class)->placeOrder(
            $container->get(UserRepository::class)->findOneBy(['email' => 'client@example.com']),
            new Cart([new CartLine($container->get(ProductRepository::class)->findOneBy(['slug' => 'mug-noir-mat']), 2)]),
            $address,
        );

        if ($paid) {
            $order->markAsPaid(new \DateTimeImmutable());
            $container->get(EntityManagerInterface::class)->flush();
        }

        return (string) $order->getReference();
    }

    private function findOrder(string $reference): Order
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $order = static::getContainer()->get(OrderRepository::class)->findOneBy(['reference' => $reference]);
        self::assertNotNull($order);

        return $order;
    }

    private function logIn(KernelBrowser $client, string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        $client->loginUser($user);
    }
}
