<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Product;
use App\Entity\ProductVariant;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Données du formulaire d'administration d'une variante (même principe que ProductData, ADR 0005).
 */
#[UniqueEntity(
    fields: ['sku'],
    entityClass: ProductVariant::class,
    identifierFieldNames: ['id'],
    message: 'Une variante porte déjà cette référence.',
)]
final class ProductVariantData
{
    // Identifiant de la variante modifiée, null à la création
    public ?int $id = null;

    // Produit de la variante : pas un champ du formulaire, il sert à vérifier les libellés
    public ?Product $product = null;

    #[Assert\Length(max: 100)]
    public ?string $label = null;

    #[Assert\NotBlank(message: 'Veuillez saisir la référence.')]
    #[Assert\Length(max: 64)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9_-]+$/', message: 'La référence ne peut contenir que des lettres sans accent, des chiffres, des tirets et des tirets bas.')]
    public string $sku = '';

    // Prix propre en centimes ; vide = prix du produit
    #[Assert\PositiveOrZero(message: 'Le prix ne peut pas être négatif.')]
    public ?int $price = null;

    #[Assert\PositiveOrZero(message: 'Le stock ne peut pas être négatif.')]
    public int $stock = 0;

    public bool $isActive = true;

    public static function forProduct(Product $product): self
    {
        $data = new self();
        $data->product = $product;

        return $data;
    }

    public static function fromVariant(ProductVariant $variant): self
    {
        $data = new self();
        $data->id = $variant->getId();
        $data->product = $variant->getProduct();
        $data->label = $variant->getLabel();
        $data->sku = (string) $variant->getSku();
        $data->price = $variant->getPrice();
        $data->stock = $variant->getStock();
        $data->isActive = $variant->isActive();

        return $data;
    }

    /**
     * Le client ne doit jamais voir un choix vide ou deux choix identiques (ADR 0017) :
     * dès qu'un produit a plusieurs variantes, chacune a un libellé, différent des autres.
     */
    #[Assert\Callback]
    public function validateLabel(ExecutionContextInterface $context): void
    {
        $otherVariants = null === $this->product ? [] : array_filter(
            $this->product->getVariants()->toArray(),
            fn (ProductVariant $variant): bool => null === $this->id || $variant->getId() !== $this->id,
        );

        // Seule variante du produit : c'est la variante par défaut, le libellé est facultatif
        if ([] === $otherVariants) {
            return;
        }

        if (null === $this->label || '' === trim($this->label)) {
            $context->buildViolation('Un libellé est obligatoire quand le produit a plusieurs variantes.')
                ->atPath('label')
                ->addViolation();

            return;
        }

        foreach ($otherVariants as $variant) {
            if (null === $variant->getLabel()) {
                $context->buildViolation('La variante {{ sku }} n\'a pas de libellé : donnez-lui d\'abord un libellé.')
                    ->setParameter('{{ sku }}', (string) $variant->getSku())
                    ->atPath('label')
                    ->addViolation();
            } elseif (0 === strcasecmp(trim($variant->getLabel()), trim($this->label))) {
                $context->buildViolation('Une autre variante de ce produit porte déjà ce libellé.')
                    ->atPath('label')
                    ->addViolation();
            }
        }
    }
}
