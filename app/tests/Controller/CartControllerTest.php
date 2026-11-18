<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PromoCode;
use App\Enum\DiscountType;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\PromoCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;

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
        $client->request('POST', '/panier/ajouter/'.$product->getId(), ['quantity' => 1]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAddTwoVariantsOfSameProduct(): void
    {
        $client = static::createClient();

        $this->addVariantToCart($client, 'mug-emaille', 'MUG-EMAILLE-BLEU', 2);
        $this->addVariantToCart($client, 'mug-emaille', 'MUG-EMAILLE-VERT', 1);

        self::assertResponseRedirects('/panier');
        $client->followRedirect();
        self::assertSelectorCount(2, '.cart-line');
        self::assertSelectorTextContains('.cart-lines', 'Mug émaillé – Bleu');
        self::assertSelectorTextContains('.cart-lines', 'Mug émaillé – Vert');
        // Prix propre de la variante verte : (2 × 16,90 €) + 18,90 € = 52,70 €
        self::assertSelectorTextContains('.cart-lines', "18,90 € l'unité");
        self::assertSelectorTextContains('.summary-total', '52,70 €');
    }

    public function testCannotAddSoldOutVariant(): void
    {
        $client = static::createClient();
        $client->request('GET', '/produits/mug-emaille');
        $token = (string) $client->getCrawler()->filter('form.add-to-cart input[name="_token"]')->attr('value');

        // Le bouton radio est désactivé, mais une requête forgée peut quand même l'envoyer
        $client->request('POST', '/panier/ajouter/'.$this->findProductId('mug-emaille'), [
            '_token' => $token,
            'variant' => $this->findVariantId('MUG-EMAILLE-ROUGE'),
            'quantity' => 1,
        ]);

        self::assertResponseRedirects('/produits/mug-emaille');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Stock insuffisant pour « Mug émaillé – Rouge »');
    }

    public function testVariantOfAnotherProductIsRejected(): void
    {
        $client = static::createClient();
        $client->request('GET', '/produits/mug-noir-mat');
        $token = (string) $client->getCrawler()->filter('form.add-to-cart input[name="_token"]')->attr('value');

        $client->request('POST', '/panier/ajouter/'.$this->findProductId('mug-noir-mat'), [
            '_token' => $token,
            'variant' => $this->findVariantId('MUG-EMAILLE-BLEU'),
            'quantity' => 1,
        ]);

        self::assertResponseStatusCodeSame(404);
    }

    public function testLegacyCartInSessionDoesNotCauseAnError(): void
    {
        $client = static::createClient();
        // Panier enregistré avant les variantes : [identifiant produit => quantité]
        $this->setSessionValue($client, 'cart', [$this->findProductId('mug-noir-mat') => 2]);

        $client->request('GET', '/panier');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.flash--error', 'Notre catalogue a évolué : votre panier a été vidé.');
        self::assertSelectorTextContains('body', 'Votre panier est vide.');
    }

    public function testApplyValidPromoCode(): void
    {
        $client = static::createClient();
        $this->createPromoCode('MOINS10', DiscountType::Percentage, 10);
        $this->addToCart($client, 'mug-noir-mat', 2);

        // Saisie en minuscules : le code est retrouvé quand même
        $this->applyPromoCode($client, 'moins10');

        self::assertResponseRedirects('/panier');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Le code promo « MOINS10 » a été appliqué.');
        // 29,80 € − 10 % (2,98 €) = 26,82 €
        self::assertSelectorTextContains('.layout-split__aside', 'Remise (MOINS10) − 2,98 €');
        self::assertSelectorTextContains('.summary-total', '26,82 €');
    }

    public function testUnknownPromoCodeIsRejected(): void
    {
        $client = static::createClient();
        $this->addToCart($client, 'mug-noir-mat', 1);

        $this->applyPromoCode($client, 'INCONNU');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Le code promo « INCONNU » n\'existe pas.');
        self::assertSelectorTextContains('.summary-total', '14,90 €');
    }

    public function testExpiredPromoCodeIsRejected(): void
    {
        $client = static::createClient();
        $this->createPromoCode('ETE', DiscountType::FixedAmount, 500)->setEndsAt(new \DateTimeImmutable('-1 day'));
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $this->addToCart($client, 'mug-noir-mat', 1);

        $this->applyPromoCode($client, 'ETE');

        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Le code promo « ETE » a expiré.');
        self::assertSelectorTextContains('.summary-total', '14,90 €');
    }

    public function testDeactivatedPromoCodeIsRemovedFromCart(): void
    {
        $client = static::createClient();
        $this->createPromoCode('MOINS10', DiscountType::Percentage, 10);
        $this->addToCart($client, 'mug-noir-mat', 1);
        $this->applyPromoCode($client, 'MOINS10');
        $client->followRedirect();

        // Relu après les requêtes : le noyau redémarre entre deux requêtes, avec un nouvel EntityManager
        $promoCode = static::getContainer()->get(PromoCodeRepository::class)->findOneByCode('MOINS10');
        self::assertNotNull($promoCode);
        $promoCode->setIsActive(false);
        static::getContainer()->get(EntityManagerInterface::class)->flush();
        $client->request('GET', '/panier');

        self::assertSelectorTextContains('body', 'Le code promo « MOINS10 » n\'est plus valable. Il a été retiré de votre panier.');
        self::assertSelectorTextContains('.summary-total', '14,90 €');
    }

    public function testRemovePromoCode(): void
    {
        $client = static::createClient();
        $this->createPromoCode('MOINS10', DiscountType::Percentage, 10);
        $this->addToCart($client, 'mug-noir-mat', 1);
        $this->applyPromoCode($client, 'MOINS10');
        $client->followRedirect();

        $client->submitForm('Retirer le code promo MOINS10');

        self::assertResponseRedirects('/panier');
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Le code promo a été retiré.');
        self::assertSelectorTextContains('.summary-total', '14,90 €');
    }

    public function testApplyPromoCodeWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();

        $client->request('POST', '/panier/code-promo', ['code' => 'MOINS10']);

        self::assertResponseStatusCodeSame(403);
    }

    private function createPromoCode(string $code, DiscountType $type, int $value): PromoCode
    {
        $promoCode = (new PromoCode())->setCode($code)->setDiscountType($type)->setValue($value);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($promoCode);
        $entityManager->flush();

        return $promoCode;
    }

    private function applyPromoCode(KernelBrowser $client, string $code): void
    {
        $client->request('GET', '/panier');
        $client->submitForm('Appliquer le code', ['code' => $code]);
    }

    private function addToCart(KernelBrowser $client, string $slug, int $quantity): void
    {
        $client->request('GET', '/produits/'.$slug);

        $client->submitForm('Ajouter au panier', [
            'quantity' => $quantity,
        ]);
    }

    private function addVariantToCart(KernelBrowser $client, string $slug, string $sku, int $quantity): void
    {
        $client->request('GET', '/produits/'.$slug);

        $client->submitForm('Ajouter au panier', [
            'variant' => (string) $this->findVariantId($sku),
            'quantity' => $quantity,
        ]);
    }

    private function findProductId(string $slug): int
    {
        $product = static::getContainer()->get(ProductRepository::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($product);

        return (int) $product->getId();
    }

    private function findVariantId(string $sku): int
    {
        $variant = static::getContainer()->get(ProductVariantRepository::class)->findOneBy(['sku' => $sku]);
        self::assertNotNull($variant);

        return (int) $variant->getId();
    }

    /**
     * Écrit une valeur dans la session du navigateur de test, comme si une visite précédente l'y avait mise.
     */
    private function setSessionValue(KernelBrowser $client, string $name, mixed $value): void
    {
        $session = static::getContainer()->get('session.factory')->createSession();
        $session->set($name, $value);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }
}
