<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Model\Cart;
use App\Model\CartLine;
use App\Model\ShippingAddress;
use App\Repository\ProductVariantRepository;
use App\Repository\UserRepository;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class DashboardControllerTest extends WebTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin');

        self::assertResponseRedirects('/connexion');
    }

    public function testCustomerIsForbidden(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $client->request('GET', '/admin');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesDashboardWithoutAnyOrder(): void
    {
        $client = static::createClient();
        $this->deleteAllOrders();
        $this->logIn($client, 'admin@example.com');

        $crawler = $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Tableau de bord');
        // L'onglet est le premier de l'administration et le lien de l'en-tête y mène
        self::assertSelectorTextContains('.tabs a:first-child[aria-current="page"]', 'Tableau de bord');
        self::assertSelectorExists('.site-nav a[href="/admin"]');
        self::assertSelectorTextContains('.stat-grid', '0,00 €');
        self::assertSelectorTextContains('.stat-grid', 'Aucune vente');
        self::assertCount(14, $crawler->filter('.bar-chart__column'));
        self::assertCount(14, $crawler->filter('.bar-chart__data tbody tr'));
        self::assertSelectorTextContains('[aria-labelledby="best-sellers-title"]', 'Aucune vente pour l\'instant.');
    }

    public function testAdminSeesSalesFigures(): void
    {
        $client = static::createClient();
        $this->deleteAllOrders();
        $this->placeOrder(quantity: 2, paid: true);     // 2 × 14,90 € = 29,80 €
        $this->placeOrder(quantity: 10, paid: false);   // en attente : ne compte pas
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.stat-grid', '29,80 €');
        self::assertSelectorTextNotContains('.stat-grid', '178,80 €');
        self::assertSelectorTextContains('[aria-labelledby="best-sellers-title"] tbody', 'Mug noir mat');
        self::assertSelectorTextContains('[aria-labelledby="best-sellers-title"] tbody', '2');
        // Dans le tableau du graphique, la vente du jour apparaît sur la dernière ligne (aujourd'hui)
        self::assertSelectorTextContains('.bar-chart__data tbody tr:last-child', '29,80 €');
    }

    public function testAdminSeesProductsToRestock(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin');

        // Mug isotherme : stock 0 dans les fixtures ; stylo plume : stock 5 (faible) ; mug blanc : 25
        $lowStock = '[aria-labelledby="low-stock-title"]';
        self::assertSelectorTextContains($lowStock, 'Mug isotherme');
        self::assertSelectorTextContains($lowStock, 'Rupture');
        self::assertSelectorTextContains($lowStock, 'Stylo plume');
        self::assertSelectorTextNotContains($lowStock, 'Mug blanc classique');
        // Variantes : seule la variante épuisée du mug émaillé est signalée, avec son libellé
        self::assertSelectorTextContains($lowStock, 'Mug émaillé – Rouge');
        self::assertSelectorTextNotContains($lowStock, 'Mug émaillé – Bleu');

        // Le lien mène à la variante à réapprovisionner
        $mug = static::getContainer()->get(ProductVariantRepository::class)->findOneBy(['sku' => 'MUG-ISOTHERME']);
        self::assertNotNull($mug);
        self::assertSelectorExists(sprintf('%s a[href="/admin/variantes/%d/modifier"]', $lowStock, $mug->getId()));
    }

    private function placeOrder(int $quantity, bool $paid): void
    {
        $container = static::getContainer();
        $address = new ShippingAddress();
        $address->fullName = 'Camille Martin';
        $address->address = '12 rue des Lilas';
        $address->postalCode = '69001';
        $address->city = 'Lyon';

        $customer = $container->get(UserRepository::class)->findOneBy(['email' => 'client@example.com']);
        $variant = $container->get(ProductVariantRepository::class)->findOneBy(['sku' => 'MUG-NOIR-MAT']);
        self::assertNotNull($customer);
        self::assertNotNull($variant);

        $order = $container->get(OrderService::class)->placeOrder(
            $customer,
            new Cart([new CartLine($variant, $quantity)]),
            $address,
        );

        if ($paid) {
            $order->markAsPaid(new \DateTimeImmutable());
            $container->get(EntityManagerInterface::class)->flush();
        }
    }

    private function deleteAllOrders(): void
    {
        // Annulé à la fin du test, comme toute écriture en base (DAMA DoctrineTestBundle)
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->createQuery('DELETE FROM App\Entity\OrderItem')->execute();
        $entityManager->createQuery('DELETE FROM App\Entity\Order')->execute();
    }

    private function logIn(KernelBrowser $client, string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $client->loginUser($user);
    }
}
