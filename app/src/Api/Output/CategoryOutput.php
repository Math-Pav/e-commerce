<?php

declare(strict_types=1);

namespace App\Api\Output;

/**
 * Une catégorie vue par l'API : le slug sert d'identifiant public (filtre ?category=), pas l'id en base.
 */
final class CategoryOutput
{
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
    ) {
    }
}
