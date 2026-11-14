<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PromoCode;
use App\Enum\DiscountType;
use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Mime\Email;

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
        self::assertSelectorTextContains('tfoot', '29,80 €');

        $client->request('GET', '/panier');
        self::assertSelectorTextContains('body', 'Votre panier est vide.');
    }

    public function testPlaceOrderSendsConfirmationEmail(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $orderUrl = $this->placeOrder($client, 'mug-noir-mat', 2);
        $reference = basename($orderUrl);

        // Vérification avant toute nouvelle requête : chaque requête vide la liste des e-mails collectés
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailAddressContains($message, 'To', 'client@example.com');
        self::assertEmailSubjectContains($message, 'Confirmation de votre commande '.$reference);
        self::assertEmailHtmlBodyContains($message, $reference);
        self::assertEmailHtmlBodyContains($message, 'Mug noir mat');
        self::assertEmailHtmlBodyContains($message, '29,80 €');
        self::assertEmailHtmlBodyContains($message, '12 rue des Lilas');
        self::assertEmailHtmlBodyContains($message, '69001 Lyon');
        self::assertEmailHtmlBodyContains($message, 'href="http://localhost'.$orderUrl.'"');
        self::assertEmailTextBodyContains($message, $reference);
        self::assertEmailTextBodyContains($message, 'Total : 29,80 €');
        self::assertEmailTextBodyContains($message, '12 rue des Lilas');
    }

    public function testOrderEmailContainsOnlyThisOrder(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $firstReference = basename($this->placeOrder($client, 'mug-noir-mat', 2));

        $secondReference = basename($this->placeOrder($client, 'stylo-a-bille-bleu', 3));

        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailSubjectContains($message, $secondReference);
        self::assertEmailHtmlBodyContains($message, 'Stylo à bille bleu');
        self::assertEmailHtmlBodyContains($message, '4,50 €');
        self::assertEmailHtmlBodyNotContains($message, $firstReference);
        self::assertEmailHtmlBodyNotContains($message, 'Mug noir mat');
        self::assertEmailHtmlBodyNotContains($message, '29,80 €');
        self::assertEmailTextBodyNotContains($message, $firstReference);
        self::assertEmailTextBodyNotContains($message, 'Mug noir mat');
    }

    public function testCheckoutIsPrefilledWithProfileAddress(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->saveProfileAddress($client, '3 place Bellecour', '69002', 'Lyon');
        $this->addToCart($client, 'mug-noir-mat', 1);

        $client->request('GET', '/commande');

        self::assertInputValueSame('shipping_address[fullName]', 'Camille Martin');
        self::assertInputValueSame('shipping_address[address]', '3 place Bellecour');
        self::assertInputValueSame('shipping_address[postalCode]', '69002');
        self::assertInputValueSame('shipping_address[city]', 'Lyon');
        self::assertCheckboxNotChecked('shipping_address[saveToProfile]');
    }

    public function testCheckoutWithoutProfileAddressHasEmptyFields(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->addToCart($client, 'mug-noir-mat', 1);

        $client->request('GET', '/commande');

        self::assertInputValueSame('shipping_address[fullName]', 'Camille Martin');
        self::assertInputValueSame('shipping_address[address]', '');
    }

    public function testSaveAddressCheckboxUpdatesProfile(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->saveProfileAddress($client, '3 place Bellecour', '69002', 'Lyon');
        $this->addToCart($client, 'mug-noir-mat', 1);

        $this->submitCheckout($client, '69001', saveToProfile: true);

        self::assertResponseRedirects();
        $client->request('GET', '/mon-compte');
        self::assertInputValueSame('profile[address]', '12 rue des Lilas');
        self::assertInputValueSame('profile[postalCode]', '69001');
        self::assertInputValueSame('profile[city]', 'Lyon');
    }

    public function testUncheckedSaveAddressKeepsProfileAndEmailUsesOrderAddress(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->saveProfileAddress($client, '3 place Bellecour', '69002', 'Lyon');
        $this->addToCart($client, 'mug-noir-mat', 1);

        $this->submitCheckout($client, '69001');

        // L'e-mail reprend l'adresse copiée dans la commande, pas celle du profil
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailHtmlBodyContains($message, '12 rue des Lilas');
        self::assertEmailHtmlBodyNotContains($message, 'Bellecour');
        self::assertEmailTextBodyContains($message, '69001 Lyon');
        self::assertEmailTextBodyNotContains($message, 'Bellecour');

        $client->request('GET', '/mon-compte');
        self::assertInputValueSame('profile[address]', '3 place Bellecour');
    }

    public function testInvalidCheckoutDoesNotSaveAddressToProfile(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->addToCart($client, 'mug-noir-mat', 1);

        $this->submitCheckout($client, 'abc', saveToProfile: true);

        self::assertResponseStatusCodeSame(422);
        self::assertCheckboxChecked('shipping_address[saveToProfile]');
        $client->request('GET', '/mon-compte');
        self::assertInputValueSame('profile[address]', '');
    }

    public function testInvalidPostalCodeShowsError(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $this->addToCart($client, 'mug-noir-mat', 1);

        $this->submitCheckout($client, 'abc');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le code postal doit contenir 5 chiffres.');
        self::assertEmailCount(0);
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

    public function testPayOrderSendsPaymentConfirmationEmail(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $orderUrl = $this->placeOrder($client, 'mug-noir-mat', 2);
        $reference = basename($orderUrl);
        $client->request('GET', $orderUrl);

        $client->submitForm('Payer la commande');

        self::assertEmailCount(1, message: 'Seul le paiement doit envoyer un e-mail pendant cette requête.');
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailAddressContains($message, 'To', 'client@example.com');
        self::assertEmailSubjectContains($message, 'Paiement reçu pour votre commande '.$reference);
        self::assertEmailHtmlBodyContains($message, '29,80 €');
        self::assertEmailTextBodyContains($message, 'paiement de 29,80 € pour la commande '.$reference);
    }

    public function testPaymentConfirmationEmailHasInvoiceAttached(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $client->request('GET', $this->placeOrder($client, 'mug-noir-mat', 2));

        $client->submitForm('Payer la commande');

        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailAttachmentCount($message, 1);
        self::assertInstanceOf(Email::class, $message);
        $attachment = $message->getAttachments()[0];
        self::assertMatchesRegularExpression('/^FA-\d{4}-\d{6}\.pdf$/', (string) $attachment->getFilename());
        self::assertSame('application/pdf', $attachment->getContentType());
        self::assertStringStartsWith('%PDF', $attachment->getBody());
        self::assertEmailTextBodyContains($message, 'Votre facture FA-');
    }

    public function testOwnerDownloadsInvoice(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $orderUrl = $this->placeAndPayOrder($client);

        $crawler = $client->request('GET', $orderUrl);
        $client->click($crawler->selectLink('Télécharger la facture')->link());

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/pdf');
        self::assertMatchesRegularExpression(
            '/^attachment; filename=FA-\d{4}-\d{6}\.pdf$/',
            (string) $client->getResponse()->headers->get('Content-Disposition'),
        );
        self::assertStringStartsWith('%PDF', (string) $client->getResponse()->getContent());
    }

    public function testAnotherCustomerCannotDownloadInvoice(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $orderUrl = $this->placeAndPayOrder($client);

        $this->logIn($client, 'admin@example.com');
        $client->request('GET', $orderUrl.'/facture');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAnonymousCannotDownloadInvoice(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $orderUrl = $this->placeAndPayOrder($client);

        // Sans cookie de session, le client redevient anonyme
        $client->getCookieJar()->clear();
        $client->request('GET', $orderUrl.'/facture');

        self::assertResponseRedirects('/connexion');
    }

    public function testUnpaidOrderHasNoInvoice(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $orderUrl = $this->placeOrder($client, 'mug-noir-mat', 1);

        $crawler = $client->request('GET', $orderUrl);
        self::assertCount(0, $crawler->selectLink('Télécharger la facture'));

        $client->request('GET', $orderUrl.'/facture');
        self::assertResponseStatusCodeSame(404);
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

        self::assertEmailCount(0, message: 'Un paiement refusé ne doit pas être confirmé par e-mail.');
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

    public function testCustomerCancelsPendingOrder(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $orderUrl = $this->placeOrder($client, 'mug-noir-mat', 2); // stock 12 → 10
        $client->request('GET', $orderUrl);

        $client->submitForm('Annuler la commande');

        self::assertResponseRedirects($orderUrl);
        self::assertEmailCount(1);
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailAddressContains($message, 'To', 'client@example.com');
        self::assertEmailSubjectContains($message, 'Annulation de votre commande '.basename($orderUrl));
        self::assertEmailHtmlBodyContains($message, '29,80 €');
        self::assertEmailTextBodyContains($message, basename($orderUrl));
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'a bien été annulée');
        self::assertSelectorTextContains('body', 'Statut : Annulée');
        self::assertSelectorNotExists('form[action$="/annuler"]');
        self::assertSelectorNotExists('form[action$="/payer"]');
        self::assertSame(12, $this->findProductStock('mug-noir-mat'), 'Le stock doit revenir à sa valeur initiale.');
    }

    public function testCustomerCannotCancelAnotherCustomerOrder(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $otherCustomerOrderUrl = $this->placeOrder($client, 'mug-noir-mat', 1);

        // Un autre client récupère un jeton CSRF valide sur sa propre commande, puis vise celle de Camille
        $this->logIn($client, 'admin@example.com');
        $token = $this->findCancelToken($client, $this->placeOrder($client, 'stylo-a-bille-bleu', 1));
        $client->request('POST', $otherCustomerOrderUrl.'/annuler', ['_token' => $token]);

        self::assertResponseStatusCodeSame(404);
        self::assertEmailCount(0);
        self::assertSame(OrderStatus::Pending, $this->findOrderStatus($otherCustomerOrderUrl));
    }

    public function testCustomerCannotCancelPaidOrder(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $orderUrl = $this->placeOrder($client, 'mug-noir-mat', 2);
        // Jeton récupéré avant le paiement : ensuite le bouton d'annulation n'est plus affiché
        $token = $this->findCancelToken($client, $orderUrl);
        $client->submitForm('Payer la commande');
        $client->followRedirect();
        self::assertSelectorNotExists('form[action$="/annuler"]');

        $client->request('POST', $orderUrl.'/annuler', ['_token' => $token]);

        self::assertResponseRedirects($orderUrl);
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Seule une commande en attente de paiement peut être annulée.');
        self::assertSelectorTextContains('body', 'Statut : Payée');
        self::assertSame(10, $this->findProductStock('mug-noir-mat'), 'Le stock ne doit pas être remis.');
    }

    public function testCancelWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $orderUrl = $this->placeOrder($client, 'mug-noir-mat', 1);

        $client->request('POST', $orderUrl.'/annuler');

        self::assertResponseStatusCodeSame(403);
        self::assertSame(OrderStatus::Pending, $this->findOrderStatus($orderUrl));
    }

    public function testDiscountIsVisibleOnOrder(): void
    {
        $client = static::createClient();
        $promoCode = (new PromoCode())->setCode('MOINS5')->setDiscountType(DiscountType::FixedAmount)->setValue(500);
        static::getContainer()->get(EntityManagerInterface::class)->persist($promoCode);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->logIn($client, 'client@example.com');
        $this->addToCart($client, 'mug-noir-mat', 2);
        $client->request('GET', '/panier');
        $client->submitForm('Appliquer le code', ['code' => 'MOINS5']);

        $this->submitCheckout($client, '69001');

        // Avant toute nouvelle requête : chaque requête vide la liste des e-mails collectés
        $message = self::getMailerMessage();
        self::assertNotNull($message);
        self::assertEmailTextBodyContains($message, 'Remise (MOINS5) : − 5,00 €');
        self::assertEmailTextBodyContains($message, 'Total : 24,80 €');

        $client->followRedirect();
        // 29,80 € − 5,00 € = 24,80 €
        self::assertSelectorTextContains('tfoot', 'Remise (MOINS5)');
        self::assertSelectorTextContains('tfoot', '− 5,00 €');
        self::assertSelectorTextContains('tfoot', '24,80 €');
        self::assertSelectorTextContains('body', 'Payer la commande · 24,80 €');
    }

    /**
     * Passe une commande via le site et renvoie l'URL de sa page (/mes-commandes/CMD-…).
     */
    private function placeOrder(KernelBrowser $client, string $slug, int $quantity): string
    {
        $this->addToCart($client, $slug, $quantity);
        $this->submitCheckout($client, '69001');

        return (string) $client->getResponse()->headers->get('Location');
    }

    private function placeAndPayOrder(KernelBrowser $client): string
    {
        $orderUrl = $this->placeOrder($client, 'mug-noir-mat', 2);
        $client->request('GET', $orderUrl);
        $client->submitForm('Payer la commande');

        return $orderUrl;
    }

    private function findCancelToken(KernelBrowser $client, string $orderUrl): string
    {
        $crawler = $client->request('GET', $orderUrl);

        return (string) $crawler->filter('form[action$="/annuler"] input[name="_token"]')->attr('value');
    }

    private function findOrderStatus(string $orderUrl): OrderStatus
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $order = static::getContainer()->get(OrderRepository::class)->findOneBy(['reference' => basename($orderUrl)]);
        self::assertNotNull($order);

        return $order->getStatus();
    }

    private function findProductStock(string $slug): int
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        // Produit sans déclinaison : sa variante par défaut porte le stock (référence = slug en majuscules)
        $variant = static::getContainer()->get(ProductVariantRepository::class)->findOneBy(['sku' => strtoupper($slug)]);
        self::assertNotNull($variant);

        return $variant->getStock();
    }

    private function logIn(KernelBrowser $client, string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $client->loginUser($user);
    }

    private function addToCart(KernelBrowser $client, string $slug, int $quantity): void
    {
        $client->request('GET', '/produits/'.$slug);
        $client->submitForm('Ajouter au panier', ['quantity' => $quantity]);
    }

    private function submitCheckout(KernelBrowser $client, string $postalCode, bool $saveToProfile = false): void
    {
        $client->request('GET', '/commande');
        $client->submitForm('Valider la commande', [
            'shipping_address[address]' => '12 rue des Lilas',
            'shipping_address[postalCode]' => $postalCode,
            'shipping_address[city]' => 'Lyon',
            'shipping_address[saveToProfile]' => $saveToProfile,
        ]);
    }

    private function saveProfileAddress(KernelBrowser $client, string $address, string $postalCode, string $city): void
    {
        $client->request('GET', '/mon-compte');
        $client->submitForm('Enregistrer mes informations', [
            'profile[address]' => $address,
            'profile[postalCode]' => $postalCode,
            'profile[city]' => $city,
        ]);
    }
}
