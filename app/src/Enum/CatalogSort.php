<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Tris proposés dans le catalogue. La valeur (à droite) est celle de l'URL (?tri=).
 *
 * Cette énumération sert de liste blanche : la valeur lue dans l'URL n'est jamais
 * insérée dans la requête SQL, elle choisit seulement l'un de ces cas (ADR 0011).
 */
enum CatalogSort: string
{
    case Name = 'nom';
    case PriceAsc = 'prix-croissant';
    case PriceDesc = 'prix-decroissant';
    case Newest = 'nouveautes';

    public const DEFAULT = self::Name;

    /**
     * Valeur inconnue ou absente : tri par défaut.
     */
    public static function fromQuery(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::DEFAULT;
    }

    public function label(): string
    {
        return match ($this) {
            self::Name => 'Nom (A à Z)',
            self::PriceAsc => 'Prix croissant',
            self::PriceDesc => 'Prix décroissant',
            self::Newest => 'Nouveautés',
        };
    }
}
