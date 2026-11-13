<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Repository\ProductRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductVariantControllerTest extends WebTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', sprintf('/admin/produits/%d/variantes', $this->findProduct('mug-emaille')->getId()));

        self::assertResponseRedirects('/connexion');
    }

    public function testCustomerIsForbidden(): void
    {
        $client = static::createClient();
        $productId = $this->findProduct('mug-emaille')->getId();
        $variantId = $this->findVariant('MUG-EMAILLE-BLEU')->getId();
        $this->logIn($client, 'client@example.com');

        $client->request('GET', sprintf('/admin/produits/%d/variantes', $productId));
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', sprintf('/admin/produits/%d/variantes/nouvelle', $productId));
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', sprintf('/admin/variantes/%d/modifier', $variantId));
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', sprintf('/admin/variantes/%d/desactiver', $variantId));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesVariantsOfProduct(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin/produits');
        $client->clickLink('Variantes (3) de Mug émaillé');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav a[href="/admin/produits"][aria-current="page"]');
        self::assertSelectorCount(3, 'tbody tr');
        self::assertSelectorTextContains('tbody', 'MUG-EMAILLE-VERT');
        self::assertSelectorTextContains('tbody', '18,90 €');
        self::assertSelectorTextContains('tbody', 'Rupture');
    }

    public function testAdminAddsVariant(): void
    {
        $client = static::createClient();
        $productId = $this->findProduct('mug-emaille')->getId();
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', sprintf('/admin/produits/%d/variantes/nouvelle', $productId));
        $client->submitForm('Ajouter la variante', [
            'product_variant[label]' => ' Jaune ',
            'product_variant[sku]' => 'MUG-EMAILLE-JAUNE',
            'product_variant[price]' => '17.50',
            'product_variant[stock]' => '4',
        ]);

        self::assertResponseRedirects(sprintf('/admin/produits/%d/variantes', $productId));
        $variant = $this->findVariant('MUG-EMAILLE-JAUNE');
        self::assertSame('Jaune', $variant->getLabel());
        self::assertSame(1750, $variant->getPrice(), 'Le prix saisi en euros doit être stocké en centimes.');
        self::assertSame(4, $variant->getStock());
        self::assertSame($productId, $variant->getProduct()?->getId());
    }

    public function testDuplicateSkuIsRejected(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', sprintf('/admin/produits/%d/variantes/nouvelle', $this->findProduct('mug-emaille')->getId()));
        $client->submitForm('Ajouter la variante', [
            'product_variant[label]' => 'Jaune',
            'product_variant[sku]' => 'MUG-NOIR-MAT',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Une variante porte déjà cette référence.');
    }

    public function testLabelIsRequiredOnceProductHasSeveralVariants(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', sprintf('/admin/produits/%d/variantes/nouvelle', $this->findProduct('mug-emaille')->getId()));
        $client->submitForm('Ajouter la variante', ['product_variant[sku]' => 'MUG-EMAILLE-SANS-NOM']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Un libellé est obligatoire quand le produit a plusieurs variantes.');
    }

    public function testDefaultVariantMustBeLabelledBeforeAddingAnother(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        // Le mug noir mat n'a que sa variante par défaut, sans libellé
        $client->request('GET', sprintf('/admin/produits/%d/variantes/nouvelle', $this->findProduct('mug-noir-mat')->getId()));
        $client->submitForm('Ajouter la variante', [
            'product_variant[label]' => 'XL',
            'product_variant[sku]' => 'MUG-NOIR-MAT-XL',
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'La variante MUG-NOIR-MAT n\'a pas de libellé : donnez-lui d\'abord un libellé.');
    }

    public function testAdminEditsVariantStockAndPrice(): void
    {
        $client = static::createClient();
        $variant = $this->findVariant('MUG-EMAILLE-ROUGE');
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', sprintf('/admin/variantes/%d/modifier', $variant->getId()));
        self::assertInputValueSame('product_variant[sku]', 'MUG-EMAILLE-ROUGE');
        $client->submitForm('Enregistrer les modifications', [
            'product_variant[stock]' => '8',
            'product_variant[price]' => '',
        ]);

        self::assertResponseRedirects(sprintf('/admin/produits/%d/variantes', $variant->getProduct()?->getId()));
        $variant = $this->findVariant('MUG-EMAILLE-ROUGE');
        self::assertSame(8, $variant->getStock());
        self::assertNull($variant->getPrice(), 'Un prix vide redonne le prix du produit.');
    }

    public function testAdminDeactivatesAndReactivatesVariant(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $client->request('GET', sprintf('/admin/produits/%d/variantes', $this->findProduct('mug-emaille')->getId()));

        $client->submitForm('Désactiver Vert');

        self::assertFalse($this->findVariant('MUG-EMAILLE-VERT')->isActive());
        $client->followRedirect();
        $client->submitForm('Activer Vert');
        self::assertTrue($this->findVariant('MUG-EMAILLE-VERT')->isActive());
    }

    public function testStatusChangeWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $client->request('POST', sprintf('/admin/variantes/%d/desactiver', $this->findVariant('MUG-EMAILLE-VERT')->getId()));

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->findVariant('MUG-EMAILLE-VERT')->isActive());
    }

    private function findProduct(string $slug): Product
    {
        $product = static::getContainer()->get(ProductRepository::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($product);

        return $product;
    }

    /**
     * Relit la variante en base (et non depuis la mémoire de Doctrine) pour vérifier ce qui a vraiment été enregistré.
     */
    private function findVariant(string $sku): ProductVariant
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $variant = static::getContainer()->get(ProductVariantRepository::class)->findOneBy(['sku' => $sku]);
        self::assertNotNull($variant, sprintf('Variante « %s » introuvable.', $sku));

        return $variant;
    }

    private function logIn(KernelBrowser $client, string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $client->loginUser($user);
    }
}
