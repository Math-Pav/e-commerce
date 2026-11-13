<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Gestion des produits depuis l'administration : création, modification, mise en ligne.
 */
final class ProductService
{
    // Slug de secours quand le nom ne contient aucun caractère utilisable (ex. « !!! »)
    private const FALLBACK_SLUG = 'produit';

    // La colonne slug fait 170 caractères : on garde de la place pour un suffixe « -2 », « -3 »…
    private const MAX_BASE_SLUG_LENGTH = 160;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
        private readonly SluggerInterface $slugger,
    ) {
    }

    public function create(Product $product): void
    {
        $product->setSlug($this->generateUniqueSlug((string) $product->getName()));

        $this->entityManager->persist($product);
        $this->entityManager->flush();
    }

    /**
     * Le slug n'est pas régénéré : l'adresse de la fiche produit reste stable (voir ADR 0004).
     */
    public function update(Product $product): void
    {
        $this->entityManager->flush();
    }

    public function activate(Product $product): void
    {
        $product->setIsActive(true);
        $this->entityManager->flush();
    }

    /**
     * Le produit n'est pas supprimé : il disparaît seulement du catalogue public.
     */
    public function deactivate(Product $product): void
    {
        $product->setIsActive(false);
        $this->entityManager->flush();
    }

    /**
     * « Mug à café » → « mug-a-cafe », puis « mug-a-cafe-2 », « mug-a-cafe-3 »… si le slug est déjà pris.
     */
    private function generateUniqueSlug(string $name): string
    {
        $baseSlug = $this->slugger->slug($name)->lower()->truncate(self::MAX_BASE_SLUG_LENGTH)->trimEnd('-')->toString();

        if ($baseSlug === '') {
            $baseSlug = self::FALLBACK_SLUG;
        }

        $slug = $baseSlug;
        $suffix = 2;

        while ($this->productRepository->isSlugTaken($slug)) {
            $slug = sprintf('%s-%d', $baseSlug, $suffix);
            ++$suffix;
        }

        return $slug;
    }
}
