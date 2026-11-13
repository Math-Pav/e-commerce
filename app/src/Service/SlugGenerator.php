<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Génère un slug unique à partir d'un texte, pour n'importe quelle entité (produit, catégorie…).
 * « Mug à café » → « mug-a-cafe », puis « mug-a-cafe-2 », « mug-a-cafe-3 »… si le slug est déjà pris.
 */
final class SlugGenerator
{
    // Place gardée en fin de colonne pour un suffixe « -2 », « -3 »…
    private const SUFFIX_RESERVED_LENGTH = 10;

    public function __construct(private readonly SluggerInterface $slugger)
    {
    }

    /**
     * @param callable(string): bool $isTaken   indique si un slug est déjà utilisé (en général une méthode de repository)
     * @param int                    $maxLength longueur de la colonne slug en base
     * @param string                 $fallback  slug utilisé quand le texte ne contient aucun caractère utilisable (ex. « !!! »)
     */
    public function generateUnique(string $text, callable $isTaken, int $maxLength, string $fallback): string
    {
        $baseSlug = $this->slugger->slug($text)
            ->lower()
            ->truncate($maxLength - self::SUFFIX_RESERVED_LENGTH)
            ->trimEnd('-')
            ->toString();

        if ('' === $baseSlug) {
            $baseSlug = $fallback;
        }

        $slug = $baseSlug;
        $suffix = 2;

        while ($isTaken($slug)) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
    }
}
