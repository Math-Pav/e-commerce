<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\PromoCode;
use App\Enum\DiscountType;
use App\Repository\ProductRepository;
use App\Repository\PromoCodeRepository;
use Doctrine\ORM\EntityManagerInterface;
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
        $client->request('POST', '/panier/ajouter/'.$product->getId(), ['quantity' => 1]);

        self::assertResponseStatusCodeSame(403);
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
        $client->submitForm('Appliquer', ['code' => $code]);
    }

    private function addToCart(KernelBrowser $client, string $slug, int $quantity): void
    {
        $client->request('GET', '/produits/'.$slug);

        $client->submitForm('Ajouter au panier', [
            'quantity' => $quantity,
        ]);
    }
}
