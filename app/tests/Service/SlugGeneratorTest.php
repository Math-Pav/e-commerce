<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\SlugGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\String\Slugger\AsciiSlugger;

final class SlugGeneratorTest extends TestCase
{
    private SlugGenerator $slugGenerator;

    protected function setUp(): void
    {
        $this->slugGenerator = new SlugGenerator(new AsciiSlugger());
    }

    #[DataProvider('textProvider')]
    public function testGeneratesSlugFromText(string $text, string $expected): void
    {
        self::assertSame($expected, $this->generate($text));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function textProvider(): iterable
    {
        yield 'majuscules et espaces' => ['Mug Noir Mat', 'mug-noir-mat'];
        yield 'accents' => ['Été à Noël', 'ete-a-noel'];
        yield 'caractères spéciaux' => ['Mug à café « Été » !', 'mug-a-cafe-ete'];
        yield 'apostrophe et ponctuation' => ["Carnets d'été, 2026", 'carnets-d-ete-2026'];
        yield 'espaces multiples en bordure' => ['  Stylos   bleus  ', 'stylos-bleus'];
    }

    public function testAddsSuffixWhenSlugIsTaken(): void
    {
        $taken = ['mug-noir', 'mug-noir-2'];

        $slug = $this->generate('Mug noir', $taken);

        self::assertSame('mug-noir-3', $slug);
    }

    public function testKeepsSlugWhenOnlyASuffixedVersionIsTaken(): void
    {
        self::assertSame('mug-noir', $this->generate('Mug noir', ['mug-noir-2']));
    }

    public function testUsesFallbackWhenTextHasNoUsableCharacter(): void
    {
        self::assertSame('defaut', $this->generate('!!! ???'));
    }

    public function testFallbackAlsoGetsSuffixWhenTaken(): void
    {
        self::assertSame('defaut-2', $this->generate('!!!', ['defaut']));
    }

    public function testKeepsRoomForSuffixInColumn(): void
    {
        $text = str_repeat('a', 150);

        $slug = $this->generate($text, [str_repeat('a', 40)], maxLength: 50);

        // 50 caractères de colonne - 10 réservés au suffixe = 40, plus « -2 »
        self::assertSame(str_repeat('a', 40) . '-2', $slug);
        self::assertLessThanOrEqual(50, strlen($slug));
    }

    public function testDoesNotEndWithDashAfterTruncation(): void
    {
        // La coupure à 40 caractères tombe juste après un tiret : il est retiré
        $slug = $this->generate(str_repeat('a', 39) . ' bbbbb', maxLength: 50);

        self::assertSame(str_repeat('a', 39), $slug);
    }

    /**
     * @param list<string> $takenSlugs slugs considérés comme déjà présents en base
     */
    private function generate(string $text, array $takenSlugs = [], int $maxLength = 120): string
    {
        return $this->slugGenerator->generateUnique(
            $text,
            static fn (string $slug): bool => in_array($slug, $takenSlugs, true),
            $maxLength,
            'defaut',
        );
    }
}
