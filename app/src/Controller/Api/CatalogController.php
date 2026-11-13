<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\CatalogOutputFactory;
use App\Api\CatalogQueryParameters;
use App\Model\Pagination;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Service\CatalogFilterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;

/**
 * API publique du catalogue, en lecture seule (docs/api.md, ADR 0020).
 * Les erreurs sont converties en application/problem+json par ApiProblemListener,
 * la limite de requêtes est appliquée par ApiRateLimitListener.
 */
#[Route('/api/v1', name: 'api_', methods: ['GET'], format: 'json')]
final class CatalogController extends AbstractController
{
    public function __construct(
        private readonly CatalogOutputFactory $outputFactory,
    ) {
    }

    #[Route('/products', name: 'product_index')]
    public function products(
        Request $request,
        ProductRepository $productRepository,
        CategoryRepository $categoryRepository,
    ): JsonResponse {
        $query = CatalogQueryParameters::toCatalogQuery($request);
        $category = null;

        if (null !== $query->categorySlug) {
            $category = $categoryRepository->findOneBy(['slug' => $query->categorySlug])
                ?? throw new BadRequestHttpException(sprintf('La catégorie « %s » n\'existe pas.', $query->categorySlug));
        }

        $products = $productRepository->findCatalogPage($query, $category);
        $pagination = new Pagination($query->page, ProductRepository::CATALOG_PAGE_SIZE, count($products));

        // Comme sur le site : la page est lue une fois, puis les variantes de toute la page en une requête
        $pageProducts = iterator_to_array($products, false);
        $productRepository->loadVariants($pageProducts);

        return $this->json($this->outputFactory->productList($pageProducts, $pagination, $query));
    }

    #[Route('/products/{slug}', name: 'product_show')]
    public function product(string $slug, ProductRepository $productRepository): JsonResponse
    {
        // Un produit désactivé est traité comme inexistant : on ne révèle pas qu'il existe
        $product = $productRepository->findOneBy(['slug' => $slug, 'isActive' => true])
            ?? throw new NotFoundHttpException(sprintf('Le produit « %s » n\'existe pas.', $slug));

        return $this->json(['data' => $this->outputFactory->productDetail($product)]);
    }

    #[Route('/categories', name: 'category_index')]
    public function categories(CatalogFilterService $catalogFilterService): JsonResponse
    {
        return $this->json(['data' => $this->outputFactory->categories($catalogFilterService->getCategories())]);
    }
}
