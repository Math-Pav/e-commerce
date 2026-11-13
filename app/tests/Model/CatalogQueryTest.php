<?php

declare(strict_types=1);

namespace App\Tests\Model;

use App\Enum\CatalogSort;
use App\Model\CatalogQuery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class CatalogQueryTest extends TestCase
{
    public function testReadsAllParametersFromRequest(): void
    {
        $query = CatalogQuery::fromRequest(new Request([
            'q' => 'mug',
            'categorie' => 'mugs-et-tasses',
            'tri' => 'prix-decroissant',
            'page' => '3',
        ]));

        self::assertSame('mug', $query->search);
        self::assertSame('mugs-et-tasses', $query->categorySlug);
        self::assertSame(CatalogSort::PriceDesc, $query->sort);
        self::assertSame(3, $query->page);
    }

    public function testMissingParametersGiveDefaults(): void
    {
        $query = CatalogQuery::fromRequest(new Request());

        self::assertNull($query->search);
        self::assertNull($query->categorySlug);
        self::assertSame(CatalogSort::Name, $query->sort);
        self::assertSame(1, $query->page);
    }

    public function testUnknownSortFallsBackToDefault(): void
    {
        $query = CatalogQuery::fromRequest(new Request(['tri' => 'price; DROP TABLE product']));

        self::assertSame(CatalogSort::Name, $query->sort);
    }

    /**
     * @param string|array<int, string> $page
     */
    #[DataProvider('invalidPageProvider')]
    public function testInvalidPageFallsBackToFirstPage(string|array $page): void
    {
        $query = CatalogQuery::fromRequest(new Request(['page' => $page]));

        self::assertSame(1, $query->page);
    }

    /**
     * @return iterable<string, array{string|array<int, string>}>
     */
    public static function invalidPageProvider(): iterable
    {
        yield 'zéro' => ['0'];
        yield 'négative' => ['-3'];
        yield 'texte' => ['abc'];
        yield 'décimale' => ['1.5'];
        yield 'trop grande pour un entier' => ['99999999999999999999'];
        yield 'tableau' => [['2']];
    }

    public function testSearchIsTrimmedAndInnerSpacesCollapsed(): void
    {
        $query = new CatalogQuery("  mug \t  noir  ");

        self::assertSame('mug noir', $query->search);
    }

    public function testBlankSearchMeansNoFilter(): void
    {
        self::assertNull((new CatalogQuery('   '))->search);
        self::assertNull((new CatalogQuery(''))->search);
    }

    public function testSearchIsTruncatedToMaxLength(): void
    {
        $query = new CatalogQuery(str_repeat('é', 150));

        self::assertSame(CatalogQuery::SEARCH_MAX_LENGTH, mb_strlen((string) $query->search));
    }

    public function testArrayParametersAreIgnored(): void
    {
        $query = CatalogQuery::fromRequest(new Request(['q' => ['mug'], 'categorie' => ['carnets']]));

        self::assertNull($query->search);
        self::assertNull($query->categorySlug);
    }

    public function testRouteParametersOmitDefaultValues(): void
    {
        self::assertSame([], (new CatalogQuery())->toRouteParameters());
        self::assertSame([], (new CatalogQuery('  ', null, CatalogSort::Name, 1))->toRouteParameters());
    }

    public function testRouteParametersKeepNonDefaultValues(): void
    {
        $query = new CatalogQuery('mug', 'mugs-et-tasses', CatalogSort::Newest, 2);

        self::assertSame(
            ['q' => 'mug', 'categorie' => 'mugs-et-tasses', 'tri' => 'nouveautes', 'page' => 2],
            $query->toRouteParameters(),
        );
    }

    public function testWithPageKeepsOtherParameters(): void
    {
        $query = new CatalogQuery('mug', 'mugs-et-tasses', CatalogSort::PriceAsc, 1);

        self::assertSame(
            ['q' => 'mug', 'categorie' => 'mugs-et-tasses', 'tri' => 'prix-croissant', 'page' => 4],
            $query->withPage(4)->toRouteParameters(),
        );
        self::assertSame(1, $query->page, 'L\'objet d\'origine ne doit pas être modifié.');
    }

    public function testChangingCategoryOrSearchGoesBackToFirstPage(): void
    {
        $query = new CatalogQuery('mug', 'mugs-et-tasses', CatalogSort::PriceAsc, 3);

        self::assertSame(
            ['q' => 'mug', 'categorie' => 'carnets', 'tri' => 'prix-croissant'],
            $query->withCategory('carnets')->toRouteParameters(),
        );
        self::assertSame(
            ['categorie' => 'mugs-et-tasses', 'tri' => 'prix-croissant'],
            $query->withoutSearch()->toRouteParameters(),
        );
    }
}
