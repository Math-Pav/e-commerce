<?php

declare(strict_types=1);

namespace App\Tests\Api;

use App\Api\CatalogQueryParameters;
use App\Enum\CatalogSort;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

final class CatalogQueryParametersTest extends TestCase
{
    public function testDefaultsWhenNoParameter(): void
    {
        $query = CatalogQueryParameters::toCatalogQuery(new Request());

        self::assertNull($query->search);
        self::assertNull($query->categorySlug);
        self::assertSame(CatalogSort::DEFAULT, $query->sort);
        self::assertSame(1, $query->page);
    }

    public function testParametersAreTranslatedToCatalogQuery(): void
    {
        $query = CatalogQueryParameters::toCatalogQuery(new Request([
            'q' => '  mug  noir ',
            'category' => 'mugs-et-tasses',
            'sort' => 'price_desc',
            'page' => '3',
        ]));

        self::assertSame('mug noir', $query->search);
        self::assertSame('mugs-et-tasses', $query->categorySlug);
        self::assertSame(CatalogSort::PriceDesc, $query->sort);
        self::assertSame(3, $query->page);
    }

    public function testEmptyValuesMeanNoFilter(): void
    {
        $query = CatalogQueryParameters::toCatalogQuery(new Request(['q' => ' ', 'category' => '', 'sort' => '', 'page' => '']));

        self::assertNull($query->search);
        self::assertNull($query->categorySlug);
        self::assertSame(CatalogSort::DEFAULT, $query->sort);
        self::assertSame(1, $query->page);
    }

    public function testInvalidPageIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        CatalogQueryParameters::toCatalogQuery(new Request(['page' => '-1']));
    }

    public function testWebSortValueIsRejected(): void
    {
        $this->expectException(BadRequestHttpException::class);

        CatalogQueryParameters::toCatalogQuery(new Request(['sort' => 'prix-croissant']));
    }

    public function testRoundTripOmitsDefaultValues(): void
    {
        $request = new Request(['q' => 'mug', 'sort' => 'newest', 'page' => '2']);

        self::assertSame(
            ['q' => 'mug', 'sort' => 'newest', 'page' => 2],
            CatalogQueryParameters::fromCatalogQuery(CatalogQueryParameters::toCatalogQuery($request)),
        );
        self::assertSame([], CatalogQueryParameters::fromCatalogQuery(CatalogQueryParameters::toCatalogQuery(new Request())));
    }
}
