<?php

declare(strict_types=1);

namespace App\Tests\Controller\Admin;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CategoryControllerTest extends WebTestCase
{
    public function testAnonymousIsRedirectedToLogin(): void
    {
        $client = static::createClient();

        $client->request('GET', '/admin/categories');

        self::assertResponseRedirects('/connexion');
    }

    public function testCustomerIsForbidden(): void
    {
        $client = static::createClient();
        $id = $this->findCategory('stylos')->getId();
        $this->logIn($client, 'client@example.com');

        $client->request('GET', '/admin/categories');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', '/admin/categories/nouvelle');
        self::assertResponseStatusCodeSame(403);

        $client->request('GET', sprintf('/admin/categories/%d/modifier', $id));
        self::assertResponseStatusCodeSame(403);

        $client->request('POST', sprintf('/admin/categories/%d/supprimer', $id));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesCategoriesWithProductCount(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $crawler = $client->request('GET', '/admin/categories');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('nav a[href="/admin/categories"][aria-current="page"]');
        // Carnets : 3 produits actifs + l'agenda désactivé = 4
        $carnetsRow = $crawler->filter('tbody tr')->reduce(static fn ($row): bool => str_contains($row->text(), 'Carnets'));
        self::assertSame('4', trim($carnetsRow->filter('td.num')->text()));
    }

    public function testAdminCreatesCategory(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $this->submitCreationForm($client, 'Agendas et plannings');

        self::assertResponseRedirects('/admin/categories');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'La catégorie « Agendas et plannings » a été créée.');
        self::assertSelectorTextContains('table', 'Agendas et plannings');
        self::assertSame('Agendas et plannings', $this->findCategory('agendas-et-plannings')->getName());
    }

    public function testCannotCreateCategoryWithExistingName(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $this->submitCreationForm($client, 'Stylos');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Une catégorie porte déjà ce nom.');
    }

    public function testCannotCreateCategoryWithEmptyName(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $this->submitCreationForm($client, '');

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Veuillez saisir le nom de la catégorie.');
    }

    public function testAdminRenamesCategoryAndSlugStaysTheSame(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $id = $this->findCategory('stylos')->getId();

        $crawler = $client->request('GET', sprintf('/admin/categories/%d/modifier', $id));
        $client->submit($crawler->selectButton('Enregistrer le nouveau nom')->form([
            'category[name]' => 'Stylos et feutres',
        ]));

        self::assertResponseRedirects('/admin/categories');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'renommée en « Stylos et feutres »');
        self::assertSame('Stylos et feutres', $this->findCategory('stylos')->getName(), 'Le slug ne doit pas changer.');
    }

    public function testRenamingWithItsOwnNameIsAllowed(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $id = $this->findCategory('stylos')->getId();

        // L'unicité ignore la catégorie elle-même : garder son nom n'est pas un doublon
        $crawler = $client->request('GET', sprintf('/admin/categories/%d/modifier', $id));
        $client->submit($crawler->selectButton('Enregistrer le nouveau nom')->form());

        self::assertResponseRedirects('/admin/categories');
    }

    public function testCannotRenameToAnotherCategoryName(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');
        $id = $this->findCategory('stylos')->getId();

        $crawler = $client->request('GET', sprintf('/admin/categories/%d/modifier', $id));
        $client->submit($crawler->selectButton('Enregistrer le nouveau nom')->form([
            'category[name]' => 'Carnets',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('body', 'Une catégorie porte déjà ce nom.');
        self::assertSame('Stylos', $this->findCategory('stylos')->getName());
    }

    public function testAdminDeletesEmptyCategory(): void
    {
        $client = static::createClient();
        $this->createCategory('Catégorie vide', 'categorie-vide');
        $this->logIn($client, 'admin@example.com');

        $crawler = $client->request('GET', '/admin/categories');
        $client->submit($crawler->selectButton('Supprimer Catégorie vide')->form());

        self::assertResponseRedirects('/admin/categories');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--success', 'La catégorie « Catégorie vide » a été supprimée.');
        self::assertSelectorTextNotContains('table', 'Catégorie vide');
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        self::assertNull(static::getContainer()->get(CategoryRepository::class)->findOneBy(['slug' => 'categorie-vide']));
    }

    public function testCannotDeleteCategoryWithProducts(): void
    {
        $client = static::createClient();
        $this->logIn($client, 'admin@example.com');

        $crawler = $client->request('GET', '/admin/categories');
        $client->submit($crawler->selectButton('Supprimer Carnets')->form());

        self::assertResponseRedirects('/admin/categories');
        $client->followRedirect();
        self::assertSelectorTextContains('.flash--error', 'Impossible de supprimer la catégorie « Carnets » : elle contient encore 4 produits.');
        self::assertSame('Carnets', $this->findCategory('carnets')->getName());
    }

    public function testDeletionWithoutCsrfTokenIsRejected(): void
    {
        $client = static::createClient();
        $id = $this->createCategory('Catégorie vide', 'categorie-vide')->getId();
        $this->logIn($client, 'admin@example.com');

        $client->request('POST', sprintf('/admin/categories/%d/supprimer', $id), ['_token' => 'invalide']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame($id, $this->findCategory('categorie-vide')->getId());
    }

    public function testPublicFiltersOnlyShowCategoriesWithActiveProducts(): void
    {
        $client = static::createClient();
        $this->createCategory('Catégorie vide', 'categorie-vide');
        // Catégorie qui ne contient qu'un produit désactivé : elle ne doit pas apparaître non plus
        $agendas = $this->createCategory('Agendas', 'agendas');
        $agenda = static::getContainer()->get(ProductRepository::class)->findOneBy(['slug' => 'agenda-2026']);
        self::assertNotNull($agenda);
        $agenda->setCategory($agendas);
        static::getContainer()->get(EntityManagerInterface::class)->flush();

        $client->request('GET', '/produits');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.chips', 'Stylos');
        self::assertSelectorTextContains('.chips', 'Carnets');
        self::assertSelectorTextNotContains('.chips', 'Catégorie vide');
        self::assertSelectorTextNotContains('.chips', 'Agendas');
    }

    private function submitCreationForm(KernelBrowser $client, string $name): void
    {
        $crawler = $client->request('GET', '/admin/categories/nouvelle');
        $client->submit($crawler->selectButton('Créer la catégorie')->form([
            'category[name]' => $name,
        ]));
    }

    private function createCategory(string $name, string $slug): Category
    {
        $category = (new Category())->setName($name)->setSlug($slug);
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($category);
        $entityManager->flush();

        return $category;
    }

    private function findCategory(string $slug): Category
    {
        static::getContainer()->get(EntityManagerInterface::class)->clear();
        $category = static::getContainer()->get(CategoryRepository::class)->findOneBy(['slug' => $slug]);
        self::assertNotNull($category);

        return $category;
    }

    private function logIn(KernelBrowser $client, string $email): void
    {
        $user = static::getContainer()->get(UserRepository::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user);
        $client->loginUser($user);
    }
}
