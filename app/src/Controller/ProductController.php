<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\CategoryRepository;
use App\Enum\CatalogSort;
use App\Model\CatalogQuery;
use App\Model\Pagination;
use Symfony\Component\HttpFoundation\Request;

final class ProductController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    #[Route('/produits', name: 'product_index', methods: ['GET'])]
    public function index(
        Request $request,
        ProductRepository $productRepository,
        CategoryRepository $categoryRepository,
    ): Response {
        $catalog = CatalogQuery::fromRequest($request);
        $currentCategory = $catalog->categorySlug !== null
            ? $categoryRepository->findOneBy(['slug' => $catalog->categorySlug])
            : null;

        $products = $productRepository->findCatalogPage($catalog, $currentCategory);
        $pagination = new Pagination($catalog->page, ProductRepository::CATALOG_PAGE_SIZE, count($products));

        // Page au-delà de la dernière (lien ancien, catalogue réduit) : on renvoie vers la dernière page
        if ($pagination->isOutOfRange()) {
            return $this->redirectToRoute('product_index', $catalog->withPage($pagination->lastPage)->toRouteParameters());
        }

        return $this->render('product/index.html.twig', [
            'products' => $products,
            'pagination' => $pagination,
            'catalog' => $catalog,
            'sorts' => CatalogSort::cases(),
            // Seules les catégories ayant au moins un produit actif apparaissent dans les filtres
            'categories' => $categoryRepository->findWithActiveProducts(),
            'currentCategory' => $currentCategory,
        ]);
    }

    #[Route('/produits/{slug}', name: 'product_show', methods: ['GET'])]
    public function show(string $slug, ProductRepository $productRepository): Response
    {
        $product = $productRepository->findOneBy(['slug' => $slug, 'isActive' => true]);

        if ($product === null) {
            throw $this->createNotFoundException('Produit introuvable.');
        }

        return $this->render('product/show.html.twig', [
            'product' => $product,
        ]);
    }
}