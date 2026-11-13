<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Category;
use App\Entity\Product;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ProductControllerTest extends WebTestCase
{
    public function testCatalogueListsOnlyActiveProducts(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le catalogue');
        self::assertAnySelectorTextContains('li', 'Mug noir mat');
        self::assertStringNotContainsString('Agenda 2026', (string) $client->getResponse()->getContent());
    }

    public function testHomePageShowsCatalogue(): void
    {
        $client = static::createClient();

        $client->request('GET', '/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Le catalogue');
    }

    public function testCatalogueCanBeFilteredByCategory(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits?categorie=carnets');

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('li', 'Carnet A5 ligné');
        self::assertStringNotContainsString('Mug noir mat', (string) $client->getResponse()->getContent());
    }

    public function testProductPageDisplaysProduct(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits/mug-noir-mat');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h1', 'Mug noir mat');
        self::assertSelectorTextContains('body', '14,90 €');
        self::assertSelectorTextContains('body', 'En stock');
    }

    public function testOutOfStockProductIsFlagged(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits/mug-isotherme');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('body', 'Rupture de stock');
    }

    #[DataProvider('unavailableSlugProvider')]
    public function testUnavailableProductReturns404(string $slug): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits/'.$slug);

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unavailableSlugProvider(): iterable
    {
        yield 'produit désactivé' => ['agenda-2026'];
        yield 'produit inexistant' => ['nimporte-quoi'];
    }

    public function testSearchCanBeCombinedWithCategory(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits?q=noir&categorie=mugs-et-tasses');

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('.product-grid li', 'Mug noir mat');
        self::assertSelectorTextContains('.toolbar__count', '1 produit');
        self::assertInputValueSame('q', 'noir');
        // La catégorie est conservée dans le formulaire et la recherche dans les filtres de catégorie
        self::assertSelectorExists('form[role="search"] input[type="hidden"][name="categorie"][value="mugs-et-tasses"]');
        self::assertSelectorExists('.chips a[href="/produits?q=noir&categorie=carnets"]');
        self::assertSelectorTextContains('.search-summary', 'noir');
        self::assertSelectorExists('.search-summary a[href="/produits?categorie=mugs-et-tasses"]');
    }

    public function testSearchWithoutResultShowsMessageAndResetLink(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits?q=mug&categorie=carnets');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.empty-state', 'Aucun produit ne correspond à « mug »');
        self::assertSelectorExists('.empty-state a[href="/produits"]');
        self::assertSelectorNotExists('.product-grid');
    }

    public function testSearchIsReflectedInPageTitle(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits?q=%20%20mug%20');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Recherche « mug »');
    }

    public function testSearchWithHtmlIsDisplayedEscaped(): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits', ['q' => '<script>alert(1)</script>']);

        self::assertResponseIsSuccessful();
        $content = (string) $client->getResponse()->getContent();
        self::assertStringNotContainsString('<script>alert(1)</script>', $content);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $content);
        self::assertSelectorTextContains('.empty-state', '<script>alert(1)</script>');
    }

    public function testUnknownSortFallsBackToNameWithoutError(): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/produits?tri=price%3B%20DROP%20TABLE%20product');

        self::assertResponseIsSuccessful();
        self::assertSame('nom', $crawler->filter('select[name="tri"] option[selected]')->attr('value'));
    }

    #[DataProvider('sortProvider')]
    public function testCatalogueCanBeSorted(string $sort, string $firstProduct): void
    {
        $client = static::createClient();

        $crawler = $client->request('GET', '/produits?tri='.$sort);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($firstProduct, $crawler->filter('.product-card__name')->first()->text());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sortProvider(): iterable
    {
        yield 'prix croissant' => ['prix-croissant', 'Stylo à bille bleu'];
        yield 'prix décroissant' => ['prix-decroissant', 'Stylo plume'];
    }

    #[DataProvider('invalidPageProvider')]
    public function testInvalidPageShowsFirstPage(string $page): void
    {
        $client = static::createClient();

        $client->request('GET', '/produits?page='.$page);

        self::assertResponseIsSuccessful();
        self::assertAnySelectorTextContains('li', 'Mug noir mat');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPageProvider(): iterable
    {
        yield 'zéro' => ['0'];
        yield 'négative' => ['-1'];
        yield 'texte' => ['abc'];
        yield 'tableau' => ['[]=2'];
        yield 'trop grande pour un entier' => ['99999999999999999999'];
    }

    public function testPageBeyondLastRedirectsToLastPage(): void
    {
        $client = static::createClient();
        $this->createProducts(15);

        $client->request('GET', '/produits?q=Produit%20de%20test&tri=prix-croissant&page=9');

        // 15 produits, 12 par page : la dernière page est la 2e ; les autres paramètres sont conservés
        self::assertResponseRedirects('/produits?q=Produit%20de%20test&tri=prix-croissant&page=2');
    }

    public function testPaginationKeepsFiltersAndIsAccessible(): void
    {
        $client = static::createClient();
        $this->createProducts(15);

        $crawler = $client->request('GET', '/produits?q=produit%20de%20test&tri=prix-croissant&page=2');

        self::assertResponseIsSuccessful();
        self::assertPageTitleContains('Recherche « produit de test » – page 2');
        self::assertCount(3, $crawler->filter('.product-grid > li'));
        self::assertSelectorTextContains('.toolbar__count', '15 produits · page 2 sur 2');

        $navigation = $crawler->filter('nav[aria-label="Pagination du catalogue"]');
        self::assertSame('Page 2', $navigation->filter('a[aria-current="page"]')->text());
        // Lien vers la page 1 : page=1 est omis, la recherche et le tri sont conservés
        self::assertSame('/produits?q=produit%20de%20test&tri=prix-croissant', $navigation->filter('a[rel="prev"]')->attr('href'));
        self::assertCount(0, $navigation->filter('a[rel="next"]'));
    }

    public function testDefaultLinksOmitDefaultParameters(): void
    {
        $client = static::createClient();
        $this->createProducts(15);

        $crawler = $client->request('GET', '/produits');

        self::assertSelectorExists('.chips a[href="/produits?categorie=carnets"]');
        self::assertSame('/produits?page=2', $crawler->filter('a[rel="next"]')->attr('href'));
        self::assertSame('false', $crawler->filter('.pagination a')->eq(1)->attr('aria-current'));
    }

    public function testCatalogueRunsAConstantNumberOfQueries(): void
    {
        $client = static::createClient();
        $this->createProducts(15);
        $debugData = $this->debugData($client);
        // On oublie les requêtes de préparation du test (insertion des produits)
        $debugData->reset();

        $client->request('GET', '/produits?q=produit&categorie=carnets');

        self::assertResponseIsSuccessful();
        // Catégorie courante, comptage, page de produits (avec leur catégorie), liste des catégories.
        // Sans la jointure, chaque carte produit déclencherait une requête de plus (N+1).
        self::assertSame(4, $this->countSelectQueries($debugData));
    }

    /**
     * Ajoute des produits actifs (annulés à la fin du test par DAMA DoctrineTestBundle).
     */
    private function createProducts(int $count): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $category = $entityManager->getRepository(Category::class)->findOneBy(['slug' => 'carnets']);
        self::assertInstanceOf(Category::class, $category);

        for ($i = 1; $i <= $count; ++$i) {
            $entityManager->persist((new Product())
                ->setName(sprintf('Produit de test %02d', $i))
                ->setSlug(sprintf('produit-de-test-%02d', $i))
                ->setPrice(10000 + $i)
                ->setStock(5)
                ->setCategory($category));
        }

        $entityManager->flush();
        $entityManager->clear();
    }

    /**
     * Le collecteur de requêtes SQL de Doctrine (actif car le mode debug est activé en test).
     */
    private function debugData(KernelBrowser $client): DebugDataHolder
    {
        $debugData = $client->getContainer()->get('doctrine.debug_data_holder');
        self::assertInstanceOf(DebugDataHolder::class, $debugData);

        return $debugData;
    }

    private function countSelectQueries(DebugDataHolder $debugData): int
    {
        $queries = $debugData->getData()['default'] ?? [];

        return count(array_filter(
            $queries,
            static fn (array $query): bool => str_starts_with((string) $query['sql'], 'SELECT'),
        ));
    }
}
