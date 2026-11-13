<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Catégories proposées dans les filtres du catalogue, relues à chaque page mais rarement modifiées : mises en cache.
 * L'étiquette CACHE_TAG est invalidée dès qu'une catégorie ou un produit est enregistré (CacheInvalidationListener, ADR 0019).
 */
final class CatalogFilterService
{
    public const CACHE_TAG = 'catalog_categories';

    private const CACHE_KEY = 'catalog.filter_categories';

    public function __construct(
        private readonly CategoryRepository $categoryRepository,
        private readonly TagAwareCacheInterface $computedCache,
    ) {
    }

    /**
     * Catégories ayant au moins un produit actif, par nom.
     * On met en cache des tableaux et non des entités : une entité relue du cache ne serait pas suivie par Doctrine.
     *
     * @return list<array{id: int, name: string, slug: string}>
     */
    public function getCategories(): array
    {
        return $this->computedCache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->tag(self::CACHE_TAG);

            return array_map(
                static fn (Category $category): array => [
                    'id' => (int) $category->getId(),
                    'name' => (string) $category->getName(),
                    'slug' => (string) $category->getSlug(),
                ],
                $this->categoryRepository->findWithActiveProducts(),
            );
        });
    }
}
