<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\Category;
use App\Entity\Product;
use App\Enum\CatalogSort;
use App\Model\CatalogQuery;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests d'intégration de ProductRepository::findCatalogPage() : les requêtes sont exécutées sur la vraie base de test.
 * Chaque test est annulé à la fin (DAMA DoctrineTestBundle) : les produits créés ici ne restent pas en base.
 */
final class ProductRepositoryCatalogTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private ProductRepository $repository;
    private Category $category;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(ProductRepository::class);

        // Une catégorie propre au test : les produits des fixtures ne faussent pas les résultats
        $this->category = (new Category())->setName('Catégorie de test')->setSlug('categorie-de-test');
        $this->entityManager->persist($this->category);
    }

    public function testSearchMatchesNameAndDescription(): void
    {
        $this->createProduct('Gourde en verre');
        $this->createProduct('Sac en toile', description: 'Pratique pour transporter une gourde.');
        $this->createProduct('Trousse');

        self::assertSame(['Gourde en verre', 'Sac en toile'], $this->names(new CatalogQuery('gourde')));
    }

    public function testSearchIgnoresCaseAndAccents(): void
    {
        $this->createProduct('Café moulu');

        // Dépend de la collation de la base (utf8mb4_uca1400_ai_ci : ai = accents ignorés, ci = casse ignorée)
        self::assertSame(['Café moulu'], $this->names(new CatalogQuery('CAFE')));
    }

    public function testSearchExcludesInactiveProducts(): void
    {
        $this->createProduct('Gourde active');
        $this->createProduct('Gourde désactivée', active: false);

        self::assertSame(['Gourde active'], $this->names(new CatalogQuery('gourde')));
    }

    public function testPercentSignIsSearchedLiterally(): void
    {
        $this->createProduct('Coton 100% bio');
        $this->createProduct('Lot de 100 enveloppes');

        // Sans échappement, « 100% » deviendrait « 100 suivi de n'importe quoi » et trouverait les deux
        self::assertSame(['Coton 100% bio'], $this->names(new CatalogQuery('100%')));
    }

    public function testUnderscoreIsSearchedLiterally(): void
    {
        $this->createProduct('Modèle stylo_bleu');
        $this->createProduct('Modèle stylo bleu');

        // Sans échappement, « _ » remplacerait n'importe quel caractère, dont l'espace
        self::assertSame(['Modèle stylo_bleu'], $this->names(new CatalogQuery('stylo_bleu')));
    }

    public function testEscapeCharacterIsSearchedLiterally(): void
    {
        $this->createProduct('Super promo !%');
        $this->createProduct('Super promo !');

        self::assertSame(['Super promo !%'], $this->names(new CatalogQuery('!%')));
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('sortProvider')]
    public function testSorts(CatalogSort $sort, array $expected): void
    {
        $this->createProduct('Banane', price: 300, createdAt: '2026-01-02');
        $this->createProduct('Ananas', price: 500, createdAt: '2026-01-01');
        $this->createProduct('Cerise', price: 100, createdAt: '2026-01-03');

        self::assertSame($expected, $this->names(new CatalogQuery(sort: $sort)));
    }

    /**
     * @return iterable<string, array{CatalogSort, list<string>}>
     */
    public static function sortProvider(): iterable
    {
        yield 'nom' => [CatalogSort::Name, ['Ananas', 'Banane', 'Cerise']];
        yield 'prix croissant' => [CatalogSort::PriceAsc, ['Cerise', 'Banane', 'Ananas']];
        yield 'prix décroissant' => [CatalogSort::PriceDesc, ['Ananas', 'Banane', 'Cerise']];
        yield 'nouveautés' => [CatalogSort::Newest, ['Cerise', 'Banane', 'Ananas']];
    }

    public function testProductsWithSamePriceKeepAStableOrder(): void
    {
        $first = $this->createProduct('Premier', price: 500);
        $second = $this->createProduct('Second', price: 500);
        $this->entityManager->flush();

        // À prix égal, l'identifiant départage : l'ordre ne change pas d'une page à l'autre
        $expected = $first->getId() < $second->getId() ? ['Premier', 'Second'] : ['Second', 'Premier'];
        self::assertSame($expected, $this->names(new CatalogQuery(sort: CatalogSort::PriceAsc)));
    }

    public function testPaginatesWithReducedPageSize(): void
    {
        foreach (['A', 'B', 'C', 'D', 'E'] as $name) {
            $this->createProduct('Produit ' . $name);
        }
        $this->entityManager->flush();

        $firstPage = $this->repository->findCatalogPage(new CatalogQuery(page: 1), $this->category, pageSize: 2);
        $lastPage = $this->repository->findCatalogPage(new CatalogQuery(page: 3), $this->category, pageSize: 2);

        self::assertCount(5, $firstPage, 'count() renvoie le total, toutes pages confondues.');
        self::assertSame(['Produit A', 'Produit B'], $this->namesOf($firstPage));
        self::assertSame(['Produit E'], $this->namesOf($lastPage));
    }

    public function testFiltersByCategory(): void
    {
        $this->createProduct('Gourde de la catégorie de test');
        $this->entityManager->flush();

        $carnets = $this->entityManager->getRepository(Category::class)->findOneBy(['slug' => 'carnets']);
        $products = $this->repository->findCatalogPage(new CatalogQuery('gourde'), $carnets);

        self::assertCount(0, $products);
    }

    private function createProduct(
        string $name,
        int $price = 1000,
        ?string $description = null,
        bool $active = true,
        ?string $createdAt = null,
    ): Product {
        $product = (new Product())
            ->setName($name)
            ->setSlug('test-' . bin2hex(random_bytes(6)))
            ->setPrice($price)
            ->setDescription($description)
            ->setIsActive($active)
            ->setCategory($this->category);

        if ($createdAt !== null) {
            // createdAt n'a pas de setter (fixée à la création) : on la force pour le test du tri « nouveautés »
            (new \ReflectionProperty(Product::class, 'createdAt'))->setValue($product, new \DateTimeImmutable($createdAt));
        }

        $this->entityManager->persist($product);

        return $product;
    }

    /**
     * @return list<string>
     */
    private function names(CatalogQuery $query): array
    {
        $this->entityManager->flush();

        return $this->namesOf($this->repository->findCatalogPage($query, $this->category));
    }

    /**
     * @param iterable<Product> $products
     *
     * @return list<string>
     */
    private function namesOf(iterable $products): array
    {
        $names = [];
        foreach ($products as $product) {
            $names[] = (string) $product->getName();
        }

        return $names;
    }
}
