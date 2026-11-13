<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Model\ProductVariantData;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestion des variantes d'un produit depuis l'administration : ajout, modification, activation (ADR 0017).
 * Pas de suppression : une variante déjà commandée reste liée aux lignes de commande ; elle se désactive.
 */
final class ProductVariantService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param ProductVariantData $data données déjà validées par le formulaire
     */
    public function create(Product $product, ProductVariantData $data): ProductVariant
    {
        $variant = new ProductVariant();
        $this->applyData($variant, $data);
        $product->addVariant($variant);

        $this->entityManager->persist($variant);
        $this->entityManager->flush();

        return $variant;
    }

    /**
     * Les commandes passées gardent leur copie du libellé et du prix.
     *
     * @param ProductVariantData $data données déjà validées par le formulaire
     */
    public function update(ProductVariant $variant, ProductVariantData $data): void
    {
        $this->applyData($variant, $data);
        $this->entityManager->flush();
    }

    public function activate(ProductVariant $variant): void
    {
        $variant->setIsActive(true);
        $this->entityManager->flush();
    }

    /**
     * La variante n'est plus proposée aux clients ; les paniers qui la contiennent la perdent avec un message.
     */
    public function deactivate(ProductVariant $variant): void
    {
        $variant->setIsActive(false);
        $this->entityManager->flush();
    }

    private function applyData(ProductVariant $variant, ProductVariantData $data): void
    {
        $label = null === $data->label ? null : trim($data->label);

        $variant
            ->setLabel('' === $label ? null : $label)
            ->setSku($data->sku)
            ->setPrice($data->price)
            ->setStock($data->stock)
            ->setIsActive($data->isActive);
    }
}
