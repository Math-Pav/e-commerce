<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductVariant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CatalogControllerTest extends WebTestCase
{
    private const PRODUCT_KEYS = ['slug', 'name', 'category', 'price', 'hasVariedPrices', 'availability', 'imageUrl'];

    public function testProductListHasDataMetaAndLinks(): void
    {
        $client = static::createClient();
        $this->addThirteenthProduct();

        $body = $this->getJson($client, '/api/v1/products');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('Content-Type', 'application/json');
        self::assertSame(['data', 'meta', 'links'], array_keys($body));
        // 12 produits actifs dans les fixtures + 1 ajouté, 12 par page
        self::assertSame(['page' => 1, 'perPage' => 12, 'total' => 13, 'lastPage' => 2], $body['meta']);
        self::assertCount(12, $body['data']);
        self::assertSame('http://localhost/api/v1/products', $body['links']['self']);
        self::assertNull($body['links']['previous']);
        self::assertSame('http://localhost/api/v1/products?page=2', $body['links']['next']);

        $first = $body['data'][0];
        self::assertSame(self::PRODUCT_KEYS, array_keys($first));
        self::assertSame(['slug', 'name'], array_keys($first['category']));
        self::assertSame(['amount', 'currency'], array_keys($first['price']));
    }

    public function testProductListShowsPriceInCentsWithCurrencyAndAvailability(): void
    {
        $client = static::createClient();

        $products = $this->indexBySlug($this->getJson($client, '/api/v1/products?category=stylos')['data']);

        self::assertSame(['amount' => 3490, 'currency' => 'EUR'], $products['stylo-plume']['price']);
        self::assertSame('low_stock', $products['stylo-plume']['availability']);
        self::assertSame('available', $products['stylo-a-bille-bleu']['availability']);
        self::assertSame(['slug' => 'stylos', 'name' => 'Stylos'], $products['stylo-plume']['category']);
    }

    public function testSecondPageLinksBackToFirstPage(): void
    {
        $client = static::createClient();
        $this->addThirteenthProduct();

        $body = $this->getJson($client, '/api/v1/products?page=2&sort=price_desc');

        self::assertSame(2, $body['meta']['page']);
        self::assertCount(1, $body['data']);
        // Du plus cher au moins cher : la gomme (0,90 €) est la dernière
        self::assertSame('gomme-blanche', $body['data'][0]['slug']);
        self::assertSame('http://localhost/api/v1/products?sort=price_desc', $body['links']['previous']);
        self::assertNull($body['links']['next']);
    }

    public function testProductListCanBeSearchedAndFiltered(): void
    {
        $client = static::createClient();

        $body = $this->getJson($client, '/api/v1/products?q=mug&category=mugs-et-tasses&sort=price_asc');

        self::assertSame(
            ['mug-blanc-classique', 'mug-noir-mat', 'mug-emaille', 'mug-isotherme'],
            array_column($body['data'], 'slug'),
        );
        self::assertSame(4, $body['meta']['total']);
        self::assertSame(
            'http://localhost/api/v1/products?q=mug&category=mugs-et-tasses&sort=price_asc',
            $body['links']['self'],
        );
    }

    public function testDisabledProductIsNotListed(): void
    {
        $client = static::createClient();

        $body = $this->getJson($client, '/api/v1/products?q=agenda');

        self::assertSame([], $body['data']);
        self::assertSame(0, $body['meta']['total']);
    }

    public function testProductDetailIncludesActiveVariants(): void
    {
        $client = static::createClient();

        $body = $this->getJson($client, '/api/v1/products/mug-emaille');

        self::assertResponseIsSuccessful();
        $product = $body['data'];
        self::assertSame(
            ['slug', 'name', 'description', 'category', 'price', 'hasVariedPrices', 'availability', 'imageUrl', 'variants'],
            array_keys($product),
        );
        self::assertSame(['amount' => 1690, 'currency' => 'EUR'], $product['price']);
        self::assertTrue($product['hasVariedPrices']);
        self::assertSame('available', $product['availability']);
        self::assertSame([
            ['sku' => 'MUG-EMAILLE-BLEU', 'label' => 'Bleu', 'price' => ['amount' => 1690, 'currency' => 'EUR'], 'availability' => 'available'],
            ['sku' => 'MUG-EMAILLE-ROUGE', 'label' => 'Rouge', 'price' => ['amount' => 1690, 'currency' => 'EUR'], 'availability' => 'out_of_stock'],
            ['sku' => 'MUG-EMAILLE-VERT', 'label' => 'Vert', 'price' => ['amount' => 1890, 'currency' => 'EUR'], 'availability' => 'low_stock'],
        ], $product['variants']);
    }

    public function testResponsesExposeNoInternalField(): void
    {
        $client = static::createClient();

        $responses = [
            $this->getJson($client, '/api/v1/products'),
            $this->getJson($client, '/api/v1/products/mug-emaille'),
            $this->getJson($client, '/api/v1/categories'),
        ];

        $keys = $this->collectKeys($responses);

        foreach (['id', 'stock', 'isActive', 'createdAt', 'imageFilename'] as $internalField) {
            self::assertNotContains($internalField, $keys);
        }
    }

    public function testCategoriesListsCategoriesWithActiveProducts(): void
    {
        $client = static::createClient();

        $body = $this->getJson($client, '/api/v1/categories');

        self::assertResponseIsSuccessful();
        self::assertSame(['data' => [
            ['slug' => 'carnets', 'name' => 'Carnets'],
            ['slug' => 'mugs-et-tasses', 'name' => 'Mugs et tasses'],
            ['slug' => 'stylos', 'name' => 'Stylos'],
        ]], $body);
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function provideErrors(): iterable
    {
        yield 'produit désactivé' => ['/api/v1/products/agenda-2026', 404];
        yield 'produit inconnu' => ['/api/v1/products/inconnu', 404];
        yield 'route inconnue' => ['/api/v1/inconnue', 404];
        yield 'tri inconnu' => ['/api/v1/products?sort=nom', 400];
        yield 'page nulle' => ['/api/v1/products?page=0', 400];
        yield 'page non numérique' => ['/api/v1/products?page=abc', 400];
        yield 'catégorie inconnue' => ['/api/v1/products?category=inconnue', 400];
        yield 'paramètre tableau' => ['/api/v1/products?q[]=mug', 400];
        yield 'recherche trop longue' => ['/api/v1/products?q='.str_repeat('a', 101), 400];
    }

    #[DataProvider('provideErrors')]
    public function testErrorsUseProblemJson(string $url, int $status): void
    {
        $client = static::createClient();

        $body = $this->getJson($client, $url);

        self::assertResponseStatusCodeSame($status);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertSame('about:blank', $body['type']);
        self::assertSame($status, $body['status']);
        self::assertIsString($body['title']);
        self::assertIsString($body['detail']);
    }

    public function testRequestsBeyondTheLimitAreRejected(): void
    {
        $client = static::createClient();

        for ($i = 1; $i <= 60; ++$i) {
            $client->request('GET', '/api/v1/categories');
            self::assertResponseIsSuccessful(sprintf('Requête n° %d refusée.', $i));
        }

        $body = $this->getJson($client, '/api/v1/categories');

        self::assertResponseStatusCodeSame(429);
        self::assertResponseHeaderSame('Content-Type', 'application/problem+json');
        self::assertResponseHasHeader('Retry-After');
        self::assertSame(429, $body['status']);

        // La limite est propre à chaque adresse IP
        $client->request('GET', '/api/v1/categories', server: ['REMOTE_ADDR' => '203.0.113.7']);
        self::assertResponseIsSuccessful();
    }

    /**
     * Les fixtures ont 12 produits actifs, soit une seule page : un 13e produit crée une deuxième page.
     */
    private function addThirteenthProduct(): void
    {
        $entityManager = static::getContainer()->get(EntityManagerInterface::class);
        $category = $entityManager->getRepository(Category::class)->findOneBy(['slug' => 'stylos']);
        self::assertNotNull($category);

        $entityManager->persist((new Product())
            ->setName('Gomme blanche')
            ->setSlug('gomme-blanche')
            ->setPrice(90)
            ->setCategory($category)
            ->addVariant((new ProductVariant())->setSku('GOMME-BLANCHE')->setStock(100)));
        $entityManager->flush();
    }

    /**
     * @return array<string, mixed>
     */
    private function getJson(KernelBrowser $client, string $url): array
    {
        $client->request('GET', $url);

        $body = json_decode((string) $client->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);

        /* @var array<string, mixed> $body */
        return $body;
    }

    /**
     * @param list<mixed> $products
     *
     * @return array<string, array<string, mixed>>
     */
    private function indexBySlug(array $products): array
    {
        /* @var list<array<string, mixed>> $products */
        return array_column($products, null, 'slug');
    }

    /**
     * Toutes les clés des réponses, à toutes les profondeurs.
     *
     * @param array<mixed> $data
     *
     * @return list<int|string>
     */
    private function collectKeys(array $data): array
    {
        $keys = [];

        foreach ($data as $key => $value) {
            $keys[] = $key;

            if (is_array($value)) {
                $keys = array_merge($keys, $this->collectKeys($value));
            }
        }

        return $keys;
    }
}
