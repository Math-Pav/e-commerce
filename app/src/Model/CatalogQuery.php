<?php

declare(strict_types=1);

namespace App\Model;

use App\Enum\CatalogSort;
use Symfony\Component\HttpFoundation\Request;

/**
 * Filtres du catalogue lus dans l'URL : recherche (?q=), catégorie (?categorie=), tri (?tri=) et page (?page=).
 *
 * Objet immuable : les méthodes with*() renvoient une copie modifiée, ce qui permet
 * de construire les liens des filtres et de la pagination sans perdre les autres paramètres.
 * Toute valeur invalide est remplacée par sa valeur par défaut au lieu de provoquer une erreur.
 */
final class CatalogQuery
{
    public const SEARCH_MAX_LENGTH = 100;

    public readonly ?string $search;
    public readonly int $page;

    public function __construct(
        ?string $search = null,
        public readonly ?string $categorySlug = null,
        public readonly CatalogSort $sort = CatalogSort::DEFAULT,
        int $page = 1,
    ) {
        $this->search = self::normalizeSearch($search);
        $this->page = max(1, $page);
    }

    public static function fromRequest(Request $request): self
    {
        $query = $request->query->all();

        return new self(
            self::stringOrNull($query['q'] ?? null),
            self::stringOrNull($query['categorie'] ?? null) ?: null,
            CatalogSort::fromQuery(self::stringOrNull($query['tri'] ?? null)),
            self::parsePage($query['page'] ?? null),
        );
    }

    public function withPage(int $page): self
    {
        return new self($this->search, $this->categorySlug, $this->sort, $page);
    }

    /**
     * Changer de catégorie ou de recherche ramène à la première page.
     */
    public function withCategory(?string $categorySlug): self
    {
        return new self($this->search, $categorySlug, $this->sort);
    }

    public function withoutSearch(): self
    {
        return new self(null, $this->categorySlug, $this->sort);
    }

    public function hasFilters(): bool
    {
        return null !== $this->search || null !== $this->categorySlug;
    }

    /**
     * Paramètres d'URL à passer à path() : ceux qui ont leur valeur par défaut sont omis,
     * pour des adresses courtes (/produits plutôt que /produits?q=&tri=nom&page=1).
     *
     * @return array<string, string|int>
     */
    public function toRouteParameters(): array
    {
        return array_filter([
            'q' => $this->search,
            'categorie' => $this->categorySlug,
            'tri' => CatalogSort::DEFAULT !== $this->sort ? $this->sort->value : null,
            'page' => $this->page > 1 ? $this->page : null,
        ], static fn (string|int|null $value): bool => null !== $value);
    }

    /**
     * Espaces retirés aux extrémités et regroupés, longueur limitée ; recherche vide = pas de filtre.
     */
    private static function normalizeSearch(?string $search): ?string
    {
        $search = trim((string) preg_replace('/\s+/u', ' ', (string) $search));
        $search = mb_substr($search, 0, self::SEARCH_MAX_LENGTH);

        return '' !== $search ? rtrim($search) : null;
    }

    /**
     * Une page absente, non numérique, inférieure à 1 ou trop grande pour un entier vaut 1.
     */
    private static function parsePage(mixed $page): int
    {
        $page = filter_var($page, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return false !== $page ? $page : 1;
    }

    /**
     * Un paramètre envoyé sous forme de tableau (?q[]=...) est ignoré.
     */
    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
