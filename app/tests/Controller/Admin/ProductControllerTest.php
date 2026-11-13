<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Product;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use App\Tests\Util\TestFiles;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Field\FileFormField;

final class ProductControllerTest extends WebTestCase
{
    private TestFiles $files;

    protected function setUp(): void
    {
        $this->files = new TestFiles();
    }

    /**
     * Nettoie les fichiers créés : les fichiers temporaires à envoyer et les photos enregistrées.
     * Le dossier des photos est propre à l'environnement de test (services.yaml) : le vider ne touche pas aux photos de développement.
     */
    protected function tearDown(): void
    {
        $this->files->cleanUp();
        TestFiles::emptyDirectory($this->imageDirectory());

        parent::tearDown();
    }

    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/produits');

        self::assertResponseRedirects('/connexion');
    }

    public function testCustomerIsForbidden(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $client->request('GET', '/admin/produits');

        self::assertResponseStatusCodeSame(403);
    }

    public function testCustomerDoesNotSeeAdminLink(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');

        $client->request('GET', '/produits');

        self::assertSelectorNotExists('a[href="/admin/produits"]');
    }

    public function testAdminSeesAllProducts(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $client->request('GET', '/admin/produits');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav a[href="/admin/produits"]');
        self::assertSelectorTextContains('table', 'Agenda 2026');
        self::assertSelectorTextContains('table', 'Désactivé');
        self::assertSelectorTextContains('table', 'Rupture');
    }

    public function testAnonymousCannotOpenCreationForm(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/produits/nouveau');

        self::assertResponseRedirects('/connexion');
    }

    public function testCustomerCannotCreateEditOrDeactivate(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'client@example.com');
        $id = $this->findProduct('mug-noir-mat')->getId();

        $client->request('GET', '/admin/produits/nouveau');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', sprintf('/admin/produits/%d/modifier', $id));
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', sprintf('/admin/produits/%d/desactiver', $id));
        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->findProduct('mug-noir-mat')->isActive());
    }

    public function testAdminCreatesProduct(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $this->submitCreationForm($client, 'Mug « Été » à rayures', '12.90');

        self::assertResponseRedirects('/admin/produits');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'Mug « Été » à rayures');

        $product = $this->findProduct('mug-ete-a-rayures');
        self::assertSame(1290, $product->getPrice(), 'Le prix saisi en euros doit être stocké en centimes.');
        self::assertSame(7, $product->getStock());
        self::assertTrue($product->isActive());

        $client->request('GET', '/produits/mug-ete-a-rayures');
        self::assertResponseIsSuccessful();
    }

    public function testCreatingProductWithExistingNameGivesUniqueSlug(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $this->submitCreationForm($client, 'Mug noir mat', '9.90');

        self::assertResponseRedirects('/admin/produits');
        self::assertSame(990, $this->findProduct('mug-noir-mat-2')->getPrice());
        self::assertSame(1490, $this->findProduct('mug-noir-mat')->getPrice());
    }

    public function testInvalidCreationShowsErrors(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $this->submitCreationForm($client, '', '-5');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Veuillez saisir le nom du produit.');
        self::assertSelectorTextContains('form', 'Le prix ne peut pas être négatif.');
    }

    public function testAdminEditsProductAndSlugStaysTheSame(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $id = $this->findProduct('mug-noir-mat')->getId();

        $crawler = $client->request('GET', sprintf('/admin/produits/%d/modifier', $id));
        self::assertResponseIsSuccessful();
        self::assertInputValueSame('product[price]', '14.90');

        $client->submit($crawler->selectButton('Enregistrer les modifications')->form([
            'product[name]' => 'Mug noir mat XL',
            'product[price]' => '16.50',
        ]));

        self::assertResponseRedirects('/admin/produits');
        $product = $this->findProduct('mug-noir-mat');
        self::assertSame('Mug noir mat XL', $product->getName());
        self::assertSame(1650, $product->getPrice());

        $client->request('GET', '/produits/mug-noir-mat');
        self::assertSelectorTextContains('h1', 'Mug noir mat XL');
    }

    public function testEditWithEmptyPriceShowsError(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $id = $this->findProduct('mug-noir-mat')->getId();

        $crawler = $client->request('GET', sprintf('/admin/produits/%d/modifier', $id));
        $client->submit($crawler->selectButton('Enregistrer les modifications')->form([
            'product[price]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'Veuillez saisir le prix.');
    }

    public function testInvalidEditDoesNotChangeProduct(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $id = $this->findProduct('mug-noir-mat')->getId();

        $crawler = $client->request('GET', sprintf('/admin/produits/%d/modifier', $id));
        $client->submit($crawler->selectButton('Enregistrer les modifications')->form([
            'product[name]' => '',
            'product[price]' => '',
        ]));

        self::assertResponseStatusCodeSame(422);
        // Le formulaire travaille sur un DTO : le produit garde son nom, même dans le titre de la page en erreur
        self::assertPageTitleContains('Modifier Mug noir mat');
        $product = $this->findProduct('mug-noir-mat');
        self::assertSame('Mug noir mat', $product->getName());
        self::assertSame(1490, $product->getPrice());
    }

    public function testDeactivatedProductDisappearsFromPublicSite(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $crawler = $client->request('GET', '/admin/produits');
        $client->submit($crawler->selectButton('Désactiver Mug noir mat')->form());

        self::assertResponseRedirects('/admin/produits');
        self::assertFalse($this->findProduct('mug-noir-mat')->isActive());

        // Toujours visible dans l'administration…
        $client->followRedirect();
        self::assertSelectorTextContains('table', 'Mug noir mat');

        // … mais plus dans le catalogue ni sur sa fiche
        $client->request('GET', '/produits');
        self::assertStringNotContainsString('Mug noir mat', (string) $client->getResponse()->getContent());

        $client->request('GET', '/produits/mug-noir-mat');
        self::assertResponseStatusCodeSame(404);
    }

    public function testActivatedProductAppearsOnPublicSite(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $crawler = $client->request('GET', '/admin/produits');
        $client->submit($crawler->selectButton('Activer Agenda 2026')->form());

        self::assertResponseRedirects('/admin/produits');
        self::assertTrue($this->findProduct('agenda-2026')->isActive());

        $client->request('GET', '/produits/agenda-2026');
        self::assertResponseIsSuccessful();
    }

    public function testDeactivationWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $id = $this->findProduct('mug-noir-mat')->getId();

        $client->request('POST', sprintf('/admin/produits/%d/desactiver', $id), ['_token' => 'invalide']);

        self::assertResponseStatusCodeSame(403);
        self::assertTrue($this->findProduct('mug-noir-mat')->isActive());
    }

    public function testDeactivationRequiresPost(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $id = $this->findProduct('mug-noir-mat')->getId();

        $client->request('GET', sprintf('/admin/produits/%d/desactiver', $id));

        self::assertResponseStatusCodeSame(405);
    }

    public function testAdminUploadsImageAndItIsDisplayed(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $this->submitCreationForm($client, 'Mug photo', '12.90', $this->files->png('Ma Photo De Vacances.png'));

        self::assertResponseRedirects('/admin/produits');
        $filename = (string) $this->findProduct('mug-photo')->getImageFilename();
        // Nom aléatoire : le nom d'origine n'est pas réutilisé
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}\.png$/', $filename);
        self::assertFileExists($this->imageDirectory().'/'.$filename);

        $client->request('GET', '/produits/mug-photo');
        self::assertSelectorExists(sprintf('.product-detail img[src="/uploads/products/%s"][alt="Mug photo"]', $filename));

        $client->request('GET', '/produits');
        self::assertSelectorExists(sprintf('.product-card img[src="/uploads/products/%s"]', $filename));
    }

    public function testImageIsShownInCartCheckoutAndOrder(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $this->submitEditForm($client, (int) $this->findProduct('mug-noir-mat')->getId(), $this->files->png());
        $image = sprintf('img[src="/uploads/products/%s"][alt="Mug noir mat"]', $this->findProduct('mug-noir-mat')->getImageFilename());

        $this->logIn($client, 'client@example.com');
        $client->request('GET', '/produits/mug-noir-mat');
        $client->submitForm('Ajouter au panier', ['quantity' => '1']);

        $client->request('GET', '/panier');
        self::assertSelectorExists('.cart-line '.$image);

        $client->request('GET', '/commande');
        self::assertSelectorExists('.summary-row '.$image);
        $client->submitForm('Valider la commande', [
            'shipping_address[address]' => '12 rue des Lilas',
            'shipping_address[postalCode]' => '69001',
            'shipping_address[city]' => 'Lyon',
        ]);
        $client->followRedirect();
        self::assertSelectorExists('.table__product '.$image);
    }

    public function testProductWithoutImageKeepsPlaceholder(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits/mug-noir-mat');

        self::assertSelectorNotExists('.product-detail img');
        self::assertSelectorExists('.product-detail div.product-visual svg');
    }

    public function testImageBetweenOneAndTwoMegabytesIsAccepted(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $this->submitCreationForm($client, 'Mug grande photo', '12.90', $this->files->largePng());

        self::assertResponseRedirects('/admin/produits');
        $filename = (string) $this->findProduct('mug-grande-photo')->getImageFilename();
        self::assertFileExists($this->imageDirectory().'/'.$filename);
        self::assertGreaterThan(1_000_000, filesize($this->imageDirectory().'/'.$filename));
    }

    public function testTooLargeImageIsRefused(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $this->submitCreationForm($client, 'Mug trop lourd', '12.90', $this->files->tooLargePng());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'La photo ne doit pas dépasser 2 Mo.');
        self::assertSame([], glob($this->imageDirectory().'/*') ?: []);
        $this->assertProductDoesNotExist('mug-trop-lourd');
    }

    public function testPhpFileRenamedAsPngIsRefused(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $this->submitCreationForm($client, 'Mug pirate', '12.90', $this->files->phpScriptNamedPng());

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form', 'La photo doit être une image JPEG, PNG ou WebP.');
        self::assertSame([], glob($this->imageDirectory().'/*') ?: []);
        $this->assertProductDoesNotExist('mug-pirate');
    }

    public function testReplacingImageDeletesPreviousFile(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $id = $this->findProduct('mug-noir-mat')->getId();
        self::assertNotNull($id);

        $this->submitEditForm($client, $id, $this->files->png());
        $firstImage = (string) $this->findProduct('mug-noir-mat')->getImageFilename();
        self::assertFileExists($this->imageDirectory().'/'.$firstImage);

        $this->submitEditForm($client, $id, $this->files->png());

        self::assertResponseRedirects('/admin/produits');
        $secondImage = (string) $this->findProduct('mug-noir-mat')->getImageFilename();
        self::assertNotSame($firstImage, $secondImage);
        self::assertFileExists($this->imageDirectory().'/'.$secondImage);
        self::assertFileDoesNotExist($this->imageDirectory().'/'.$firstImage);
    }

    public function testRemovingImageDeletesFileAndShowsPlaceholder(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $id = $this->findProduct('mug-noir-mat')->getId();
        self::assertNotNull($id);
        $this->submitEditForm($client, $id, $this->files->png());
        $image = (string) $this->findProduct('mug-noir-mat')->getImageFilename();

        $crawler = $client->request('GET', sprintf('/admin/produits/%d/modifier', $id));
        self::assertSelectorExists(sprintf('figure img[src="/uploads/products/%s"]', $image));
        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        $removeImageField = $form['product[removeImage]'];
        self::assertInstanceOf(ChoiceFormField::class, $removeImageField);
        $removeImageField->tick();
        $client->submit($form);

        self::assertResponseRedirects('/admin/produits');
        self::assertNull($this->findProduct('mug-noir-mat')->getImageFilename());
        self::assertFileDoesNotExist($this->imageDirectory().'/'.$image);

        $client->request('GET', '/produits/mug-noir-mat');
        self::assertSelectorNotExists('.product-detail img');
    }

    public function testRemoveCheckboxIsHiddenWhenProductHasNoImage(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $id = $this->findProduct('mug-noir-mat')->getId();

        $client->request('GET', sprintf('/admin/produits/%d/modifier', $id));

        self::assertSelectorExists('input[type="file"][name="product[image]"]');
        self::assertSelectorNotExists('input[name="product[removeImage]"]');
    }

    private function submitEditForm(KernelBrowser $client, int $id, string $imagePath): void
    {
        $crawler = $client->request('GET', sprintf('/admin/produits/%d/modifier', $id));
        $form = $crawler->selectButton('Enregistrer les modifications')->form();
        $imageField = $form['product[image]'];
        self::assertInstanceOf(FileFormField::class, $imageField);
        $imageField->upload($imagePath);
        $client->submit($form);
    }

    private function assertProductDoesNotExist(string $slug): void
    {
        self::assertNull(static::getContainer()->get(ProductRepository::class)->findOneBy(['slug' => $slug]));
    }

    private function imageDirectory(): string
    {
        return static::getContainer()->getParameter('app.product_images_directory');
    }

    private function submitCreationForm(KernelBrowser $client, string $name, string $price, ?string $imagePath = null): void
    {
        $category = static::getContainer()->get(CategoryRepository::class)->findOneBy(['slug' => 'mugs-et-tasses']);
        self::assertNotNull($category);

        $crawler = $client->request('GET', '/admin/produits/nouveau');
        self::assertResponseIsSuccessful();

        $form = $crawler->selectButton('Créer le produit')->form([
            'product[name]' => $name,
            'product[category]' => (string) $category->getId(),
            'product[price]' => $price,
            'product[stock]' => '7',
            'product[description]' => 'Un mug de test.',
        ]);
        if ($imagePath !== null) {
            $imageField = $form['product[image]'];
            self::assertInstanceOf(FileFormField::class, $imageField);
            $imageField->upload($imagePath);
        }
        $client->submit($form);
    }

    /**
     * Relit le produit en base (et non depuis la mémoire de Doctrine) pour vérifier ce qui a vraiment été enregistré.
     */
    private function findProduct(string $slug): Product
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $product = static::getContainer()->get(ProductRepository::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($product, sprintf('Produit « %s » introuvable.', $slug));

        return $product;
    }

    private function logIn(KernelBrowser $client, string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $client->loginUser($user);
    }
}
