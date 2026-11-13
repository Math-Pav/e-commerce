<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Category;
use App\Model\CategoryData;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestion des catégories depuis l'administration : création, renommage, suppression.
 */
final class CategoryService
{
    // Slug de secours quand le nom ne contient aucun caractère utilisable (ex. « !!! »)
    private const FALLBACK_SLUG = 'categorie';

    // Longueur de la colonne category.slug
    private const SLUG_MAX_LENGTH = 120;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly CategoryRepository $categoryRepository,
        private readonly ProductRepository $productRepository,
        private readonly SlugGenerator $slugGenerator,
    ) {
    }

    /**
     * @param CategoryData $data données déjà validées par le formulaire
     */
    public function create(CategoryData $data): Category
    {
        $category = (new Category())
            ->setName($data->name)
            ->setSlug($this->slugGenerator->generateUnique(
                $data->name,
                $this->categoryRepository->isSlugTaken(...),
                self::SLUG_MAX_LENGTH,
                self::FALLBACK_SLUG,
            ));

        $this->entityManager->persist($category);
        $this->entityManager->flush();

        return $category;
    }

    /**
     * Le slug n'est pas régénéré : l'adresse du filtre du catalogue reste stable (même règle que les produits, ADR 0004).
     *
     * @param CategoryData $data données déjà validées par le formulaire
     */
    public function rename(Category $category, CategoryData $data): void
    {
        $category->setName($data->name);
        $this->entityManager->flush();
    }

    /**
     * @throws \DomainException si la catégorie contient encore des produits (actifs ou désactivés)
     */
    public function delete(Category $category): void
    {
        $productCount = $this->productRepository->count(['category' => $category]);

        if ($productCount > 0) {
            throw new \DomainException(sprintf(
                'Impossible de supprimer la catégorie « %s » : elle contient encore %d produit%s. Changez-les de catégorie avant de la supprimer.',
                $category->getName(),
                $productCount,
                $productCount > 1 ? 's' : '',
            ));
        }

        $this->entityManager->remove($category);
        $this->entityManager->flush();
    }
}
