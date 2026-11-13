<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\PromoCode;
use App\Enum\DiscountType;
use App\Repository\PromoCodeRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PromoCodeControllerTest extends WebTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/codes-promo');

        self::assertResponseRedirects('/connexion');
    }

    public function testCustomerIsForbidden(): void
    {
        $client = static::createClient();
        $id = $this->createPromoCode('NOEL')->getId();
        $this->logIn($client, 'client@example.com');

        $client->request('GET', '/admin/codes-promo');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/codes-promo/nouveau');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', sprintf('/admin/codes-promo/%d/modifier', $id));
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', sprintf('/admin/codes-promo/%d/desactiver', $id));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminCreatesPromoCodeInUppercase(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin/codes-promo/nouveau');
        self::assertSelectorExists('nav a[href="/admin/codes-promo"][aria-current="page"]');
        $client->submitForm('Créer le code promo', [
            'promo_code[code]' => 'bienvenue5',
            'promo_code[discountType]' => 'fixed_amount',
            'promo_code[amount]' => '5.00',
            'promo_code[minimumAmount]' => '30.00',
        ]);

        self::assertResponseRedirects('/admin/codes-promo');
        $promoCode = $this->findPromoCode('BIENVENUE5');
        self::assertSame(DiscountType::FixedAmount, $promoCode->getDiscountType());
        self::assertSame(500, $promoCode->getValue());
        self::assertSame(3000, $promoCode->getMinimumAmount());
    }

    public function testPercentageAboveHundredIsRejected(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin/codes-promo/nouveau');
        $client->submitForm('Créer le code promo', [
            'promo_code[code]' => 'TROP',
            'promo_code[discountType]' => 'percentage',
            'promo_code[percentage]' => '150',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Le pourcentage doit être compris entre 1 et 100.');
    }

    public function testAdminEditsPromoCode(): void
    {
        $client = static::createClient();
        $id = $this->createPromoCode('NOEL')->getId();
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', sprintf('/admin/codes-promo/%d/modifier', $id));
        $client->submitForm('Enregistrer les modifications', ['promo_code[percentage]' => '20']);

        self::assertResponseRedirects('/admin/codes-promo');
        self::assertSame(20, $this->findPromoCode('NOEL')->getValue());
    }

    public function testAdminDeactivatesPromoCode(): void
    {
        $client = static::createClient();
        $this->createPromoCode('NOEL');
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin/codes-promo');
        $client->submitForm('Désactiver NOEL');

        self::assertResponseRedirects('/admin/codes-promo');
        self::assertFalse($this->findPromoCode('NOEL')->isActive());
    }

    public function testDeactivateWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $id = $this->createPromoCode('NOEL')->getId();
        $this->logIn($client, 'admin@example.com');

        $client->request('POST', sprintf('/admin/codes-promo/%d/desactiver', $id));

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->findPromoCode('NOEL')->isActive());
    }

    private function createPromoCode(string $code): PromoCode
    {
        $promoCode = (new PromoCode())->setCode($code)->setDiscountType(DiscountType::Percentage)->setValue(10);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($promoCode);
        $entityManager->flush();

        return $promoCode;
    }

    private function findPromoCode(string $code): PromoCode
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $promoCode = static::getContainer()->get(PromoCodeRepository::class)->findOneByCode($code);
        self::assertNotNull($promoCode);

        return $promoCode;
    }

    private function logIn(KernelBrowser $client, string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $client->loginUser($user);
    }
}
