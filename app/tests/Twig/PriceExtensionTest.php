<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Twig\PriceExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PriceExtensionTest extends TestCase
{
    #[DataProvider('priceProvider')]
    public function testFormatPrice(int $cents, string $expected): void
    {
        $extension = new PriceExtension();

        $formatted = $extension->formatPrice($cents);

        self::assertSame($expected, $formatted);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function priceProvider(): iterable
    {
        yield 'prix courant' => [1290, '12,90 €'];
        yield 'moins d\'un euro' => [50, '0,50 €'];
        yield 'zéro' => [0, '0,00 €'];
        yield 'plus de mille euros' => [123456, '1 234,56 €'];
    }
}