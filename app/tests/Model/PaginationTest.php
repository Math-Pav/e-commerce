<?php

declare(strict_types=1);

namespace App\Tests\Model;

use App\Model\Pagination;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    public function testComputesLastPage(): void
    {
        self::assertSame(1, (new Pagination(1, 12, 12))->lastPage);
        self::assertSame(2, (new Pagination(1, 12, 13))->lastPage);
    }

    public function testNoResultStillHasOnePage(): void
    {
        $pagination = new Pagination(1, 12, 0);

        self::assertSame(1, $pagination->lastPage);
        self::assertFalse($pagination->isOutOfRange());
        self::assertSame([1], $pagination->pages());
    }

    public function testDetectsPageBeyondLastOne(): void
    {
        self::assertTrue((new Pagination(3, 12, 20))->isOutOfRange());
        self::assertFalse((new Pagination(2, 12, 20))->isOutOfRange());
    }

    public function testPreviousAndNext(): void
    {
        $first = new Pagination(1, 10, 30);
        $middle = new Pagination(2, 10, 30);
        $last = new Pagination(3, 10, 30);

        self::assertFalse($first->hasPrevious());
        self::assertTrue($first->hasNext());
        self::assertTrue($middle->hasPrevious());
        self::assertTrue($middle->hasNext());
        self::assertTrue($last->hasPrevious());
        self::assertFalse($last->hasNext());
    }

    /**
     * @param list<int|null> $expected
     */
    #[DataProvider('compactPagesProvider')]
    public function testPagesAreCompacted(int $currentPage, int $lastPage, array $expected): void
    {
        $pagination = new Pagination($currentPage, 1, $lastPage);

        self::assertSame($expected, $pagination->pages());
    }

    /**
     * @return iterable<string, array{int, int, list<int|null>}>
     */
    public static function compactPagesProvider(): iterable
    {
        yield 'peu de pages : toutes affichées' => [1, 3, [1, 2, 3]];
        yield 'début' => [1, 20, [1, 2, null, 20]];
        yield 'milieu' => [10, 20, [1, null, 9, 10, 11, null, 20]];
        yield 'fin' => [20, 20, [1, null, 19, 20]];
        yield 'une seule page sautée est affichée plutôt que « … »' => [4, 20, [1, 2, 3, 4, 5, null, 20]];
    }
}
