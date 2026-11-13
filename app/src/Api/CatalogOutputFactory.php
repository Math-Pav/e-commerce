<?php

declare(strict_types=1);

namespace App\Api;

use App\Api\Output\Availability;
use App\Api\Output\CategoryOutput;
use App\Api\Output\Price;
use App\Api\Output\ProductDetailOutput;
use App\Api\Output\ProductListOutput;
use App\Api\Output\ProductOutput;
use App\Api\Output\VariantOutput;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Model\CatalogQuery;
use App\Model\Pagination;
use Symfony\Component\HttpFoundation\UrlHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Transforme les entités en objets de sortie de l'API (ADR 0020).
 * C'est le seul endroit qui connaît à la fois les entités et le contrat de l'API :
 * une modification d'entité se répercute ici, sans changer le JSON renvoyé.
 */
final class CatalogOutputFactory
{
    public function __construct(
        private readonly UrlHelper $urlHelper,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param list<Product> $products
     */
    public function productList(array $products, Pagination $pagination, CatalogQuery $query): ProductListOutput
    {
        return new ProductListOutput(
            array_map($this->product(...), $products),
            [
                'page' => $pagination->currentPage,
                'perPage' => $pagination->perPage,
                'total' => $pagination->totalItems,
                'lastPage' => $pagination->lastPage,
            ],
            [
                'self' => $this->pageUrl($query),
                'previous' => $pagination->hasPrevious() ? $this->pageUrl($query->withPage($query->page - 1)) : null,
                'next' => $pagination->hasNext() ? $this->pageUrl($query->withPage($query->page + 1)) : null,
            ],
        );
    }

    public function product(Product $product): ProductOutput
    {
        return new ProductOutput(
            (string) $product->getSlug(),
            (string) $product->getName(),
            $this->category($product),
            new Price($product->getFromPrice()),
            $product->hasVariedPrices(),
            Availability::fromProduct($product),
            $this->imageUrl($product),
        );
    }

    public function productDetail(Product $product): ProductDetailOutput
    {
        return new ProductDetailOutput(
            (string) $product->getSlug(),
            (string) $product->getName(),
            $product->getDescription(),
            $this->category($product),
            new Price($product->getFromPrice()),
            $product->hasVariedPrices(),
            Availability::fromProduct($product),
            $this->imageUrl($product),
            array_map(
                static fn (ProductVariant $variant): VariantOutput => new VariantOutput(
                    (string) $variant->getSku(),
                    $variant->getLabel(),
                    new Price($variant->getEffectivePrice()),
                    Availability::fromVariant($variant),
                ),
                $product->getActiveVariants(),
            ),
        );
    }

    /**
     * @param list<array{id: int, name: string, slug: string}> $categories catégories du cache (CatalogFilterService)
     *
     * @return list<CategoryOutput>
     */
    public function categories(array $categories): array
    {
        return array_map(
            static fn (array $category): CategoryOutput => new CategoryOutput($category['slug'], $category['name']),
            $categories,
        );
    }

    private function category(Product $product): CategoryOutput
    {
        return new CategoryOutput(
            (string) $product->getCategory()?->getSlug(),
            (string) $product->getCategory()?->getName(),
        );
    }

    /**
     * Adresse absolue : une application mobile ne connaît pas l'hôte du site.
     */
    private function imageUrl(Product $product): ?string
    {
        $filename = $product->getImageFilename();

        return null === $filename ? null : $this->urlHelper->getAbsoluteUrl('/uploads/products/'.$filename);
    }

    private function pageUrl(CatalogQuery $query): string
    {
        return $this->urlGenerator->generate(
            'api_product_index',
            CatalogQueryParameters::fromCatalogQuery($query),
            UrlGeneratorInterface::ABSOLUTE_URL,
        );
    }
}
