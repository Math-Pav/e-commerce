<?php

declare(strict_types=1);

namespace App\Api\Output;

/**
 * Une page du catalogue : les produits (data), la pagination (meta) et les liens vers les pages voisines (links).
 */
final class ProductListOutput
{
    /**
     * @param list<ProductOutput>                                           $data
     * @param array{page: int, perPage: int, total: int, lastPage: int}     $meta
     * @param array{self: string, previous: string|null, next: string|null} $links
     */
    public function __construct(
        public readonly array $data,
        public readonly array $meta,
        public readonly array $links,
    ) {
    }
}
