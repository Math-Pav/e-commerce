<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Model\ProductData;
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
        private readonly ProductImageStorage $imageStorage,
    ) {
    }

    /**
     * @param ProductData $data données déjà validées par le formulaire
     */
    public function create(ProductData $data): Product
    {
        $product = new Product();
        $this->applyData($product, $data);
        $this->applyImage($product, $data);
        $product->setSlug($this->generateUniqueSlug($data->name));

        $this->entityManager->persist($product);
        $this->entityManager->flush();

        return $product;
    }

    /**
     * Le slug n'est pas régénéré : l'adresse de la fiche produit reste stable (voir ADR 0004).
     * L'ancienne photo est supprimée du disque si elle est remplacée ou retirée.
     *
     * @param ProductData $data données déjà validées par le formulaire
     */
    public function update(Product $product, ProductData $data): void
    {
        $previousImage = $product->getImageFilename();

        $this->applyData($product, $data);
        $this->applyImage($product, $data);
        $this->entityManager->flush();

        // Suppression après l'enregistrement en base : si flush() échoue, le produit pointe toujours vers un fichier existant
        if ($previousImage !== null && $previousImage !== $product->getImageFilename()) {
            $this->imageStorage->remove($previousImage);
        }
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

    private function applyData(Product $product, ProductData $data): void
    {
        if ($data->price === null || $data->category === null) {
            throw new \InvalidArgumentException('Les données du produit doivent être validées avant d\'être enregistrées.');
        }

        $product
            ->setName($data->name)
            ->setCategory($data->category)
            ->setPrice($data->price)
            ->setStock($data->stock)
            ->setDescription($data->description);
    }

    /**
     * Une nouvelle photo remplace l'actuelle ; sinon, la case « Retirer » enlève la photo.
     */
    private function applyImage(Product $product, ProductData $data): void
    {
        if ($data->image !== null) {
            $product->setImageFilename($this->imageStorage->store($data->image));
        } elseif ($data->removeImage) {
            $product->setImageFilename(null);
        }
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
