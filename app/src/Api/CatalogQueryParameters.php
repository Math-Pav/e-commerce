<?php

declare(strict_types=1);

namespace App\Api;

use App\Enum\CatalogSort;
use App\Model\CatalogQuery;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Paramètres de GET /api/v1/products : q, category, sort, page.
 *
 * Contrairement au site (CatalogQuery::fromRequest), qui remplace en silence une valeur invalide,
 * l'API la refuse (400) : un programme qui envoie un mauvais paramètre doit le savoir.
 * Les noms et valeurs sont propres à l'API (anglais), indépendants des adresses du site :
 * renommer ?tri= sur le site ne doit pas casser la v1.
 */
final class CatalogQueryParameters
{
    /**
     * Valeurs acceptées pour ?sort=, associées aux tris du catalogue.
     */
    public const SORTS = [
        'name' => CatalogSort::Name,
        'price_asc' => CatalogSort::PriceAsc,
        'price_desc' => CatalogSort::PriceDesc,
        'newest' => CatalogSort::Newest,
    ];

    /**
     * @throws BadRequestHttpException si un paramètre est invalide
     */
    public static function toCatalogQuery(Request $request): CatalogQuery
    {
        $search = self::string($request, 'q');

        if (null !== $search && mb_strlen($search) > CatalogQuery::SEARCH_MAX_LENGTH) {
            throw new BadRequestHttpException(sprintf('Le paramètre « q » ne doit pas dépasser %d caractères.', CatalogQuery::SEARCH_MAX_LENGTH));
        }

        return new CatalogQuery($search, self::string($request, 'category'), self::sort($request), self::page($request));
    }

    /**
     * Paramètres d'URL décrivant cette recherche (pour les liens de pagination), valeurs par défaut omises.
     *
     * @return array<string, string|int>
     */
    public static function fromCatalogQuery(CatalogQuery $query): array
    {
        return array_filter([
            'q' => $query->search,
            'category' => $query->categorySlug,
            'sort' => CatalogSort::DEFAULT !== $query->sort ? array_search($query->sort, self::SORTS, true) : null,
            'page' => $query->page > 1 ? $query->page : null,
        ], static fn (string|int|false|null $value): bool => null !== $value && false !== $value);
    }

    /**
     * Paramètre texte facultatif ; absent ou vide = null. Un tableau (?q[]=...) est refusé.
     */
    private static function string(Request $request, string $name): ?string
    {
        $value = $request->query->all()[$name] ?? null;

        if (null !== $value && !is_string($value)) {
            throw new BadRequestHttpException(sprintf('Le paramètre « %s » doit être une chaîne de caractères.', $name));
        }

        return '' === trim((string) $value) ? null : $value;
    }

    private static function sort(Request $request): CatalogSort
    {
        $sort = self::string($request, 'sort');

        if (null === $sort) {
            return CatalogSort::DEFAULT;
        }

        return self::SORTS[$sort] ?? throw new BadRequestHttpException(sprintf('Le paramètre « sort » doit valoir : %s.', implode(', ', array_keys(self::SORTS))));
    }

    private static function page(Request $request): int
    {
        $page = self::string($request, 'page');

        if (null === $page) {
            return 1;
        }

        return filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            ?: throw new BadRequestHttpException('Le paramètre « page » doit être un entier supérieur ou égal à 1.');
    }
}
