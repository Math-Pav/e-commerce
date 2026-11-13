<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\Category;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Données du formulaire d'administration d'une catégorie (même principe que ProductData, ADR 0005).
 *
 * UniqueEntity vérifie en base qu'aucune AUTRE catégorie ne porte ce nom :
 * entityClass indique l'entité à interroger, identifierFieldNames permet d'ignorer
 * la catégorie en cours de modification (même id).
 */
#[UniqueEntity(
    fields: ['name'],
    entityClass: Category::class,
    identifierFieldNames: ['id'],
    message: 'Une catégorie porte déjà ce nom.',
)]
final class CategoryData
{
    // Identifiant de la catégorie modifiée, null à la création
    public ?int $id = null;

    #[Assert\NotBlank(message: 'Veuillez saisir le nom de la catégorie.')]
    #[Assert\Length(max: 100)]
    public string $name = '';

    /**
     * Pré-remplit le formulaire de modification avec les valeurs actuelles de la catégorie.
     */
    public static function fromCategory(Category $category): self
    {
        $data = new self();
        $data->id = $category->getId();
        $data->name = (string) $category->getName();

        return $data;
    }
}
