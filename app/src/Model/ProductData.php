<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Category;
use App\Entity\Product;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données du formulaire d'administration d'un produit.
 * Le formulaire remplit cet objet (même invalide) ; ProductService ne reporte sur Product que des données validées.
 */
final class ProductData
{
    #[Assert\NotBlank(message: 'Veuillez saisir le nom du produit.')]
    #[Assert\Length(max: 150)]
    public string $name = '';

    #[Assert\NotNull(message: 'Veuillez choisir une catégorie.')]
    public ?Category $category = null;

    // Prix en centimes : 1299 = 12,99 €. Nullable car le champ peut être laissé vide.
    #[Assert\NotNull(message: 'Veuillez saisir le prix.')]
    #[Assert\PositiveOrZero(message: 'Le prix ne peut pas être négatif.')]
    public ?int $price = null;

    #[Assert\PositiveOrZero(message: 'Le stock ne peut pas être négatif.')]
    public int $stock = 0;

    #[Assert\Length(max: 5000)]
    public ?string $description = null;

    // Nouvelle photo envoyée (facultative). Vide à la modification : un champ fichier ne se pré-remplit pas.
    // « 2M » = 2 000 000 octets. Le type est déduit du contenu du fichier (symfony/mime), pas de son extension :
    // un script renommé en .png est refusé. Pas de SVG : il peut contenir du JavaScript.
    #[Assert\Image(
        maxSize: '2M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        maxSizeMessage: 'La photo ne doit pas dépasser 2 Mo.',
        mimeTypesMessage: 'La photo doit être une image JPEG, PNG ou WebP.',
        uploadIniSizeErrorMessage: 'La photo ne doit pas dépasser 2 Mo.',
        uploadErrorMessage: 'La photo n\'a pas pu être envoyée. Veuillez réessayer.',
    )]
    public ?UploadedFile $image = null;

    // Case « Retirer la photo actuelle » (ignorée si une nouvelle photo est envoyée : elle remplace l'actuelle)
    public bool $removeImage = false;

    /**
     * Pré-remplit le formulaire de modification avec les valeurs actuelles du produit.
     */
    public static function fromProduct(Product $product): self
    {
        $data = new self();
        $data->name = (string) $product->getName();
        $data->category = $product->getCategory();
        $data->price = $product->getPrice();
        $data->stock = $product->getStock();
        $data->description = $product->getDescription();

        return $data;
    }
}
