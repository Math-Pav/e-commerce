<?php

declare(strict_types=1);

namespace App\Controller;

use App\Enum\CatalogSort;
use App\Model\CatalogQuery;
use App\Model\Pagination;
use App\Repository\CategoryRepository;
use App\Repository\ProductRepository;
use App\Service\CatalogFilterService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ProductController extends AbstractController
{
    /**
     * Nombre de produits de la sélection « nouveautés » de l'accueil : une rangée de la grille sur ordinateur.
     */
    private const HOME_LATEST_PRODUCTS = 4;

    #[Route('/', name: 'home', methods: ['GET'])]
    public function home(ProductRepository $productRepository, CatalogFilterService $catalogFilterService): Response
    {
        $latestProducts = $productRepository->findLatestActive(self::HOME_LATEST_PRODUCTS);
        // Prix « à partir de » et rupture de stock dépendent des variantes : une requête pour toute la sélection
        $productRepository->loadVariants($latestProducts);

        return $this->render('product/home.html.twig', [
            'latestProducts' => $latestProducts,
            // Catégories ayant au moins un produit actif, déjà en cache pour les filtres du catalogue (ADR 0019)
            'categories' => $catalogFilterService->getCategories(),
        ]);
    }

    #[Route('/produits', name: 'product_index', methods: ['GET'])]
    public function index(
        Request $request,
        ProductRepository $productRepository,
        CategoryRepository $categoryRepository,
        CatalogFilterService $catalogFilterService,
    ): Response {
        $catalog = CatalogQuery::fromRequest($request);
        $currentCategory = null !== $catalog->categorySlug
            ? $categoryRepository->findOneBy(['slug' => $catalog->categorySlug])
            : null;

        $products = $productRepository->findCatalogPage($catalog, $currentCategory);
        $pagination = new Pagination($catalog->page, ProductRepository::CATALOG_PAGE_SIZE, count($products));

        // Page au-delà de la dernière (lien ancien, catalogue réduit) : on renvoie vers la dernière page
        if ($pagination->isOutOfRange()) {
            return $this->redirectToRoute('product_index', $catalog->withPage($pagination->lastPage)->toRouteParameters());
        }

        // Le Paginator relance sa requête à chaque parcours : on lit la page une seule fois.
        // Prix « à partir de » et rupture de stock dépendent des variantes : une requête pour toute la page.
        $pageProducts = iterator_to_array($products, false);
        $productRepository->loadVariants($pageProducts);

        return $this->render('product/index.html.twig', [
            'products' => $pageProducts,
            'pagination' => $pagination,
            'catalog' => $catalog,
            'sorts' => CatalogSort::cases(),
            // Seules les catégories ayant au moins un produit actif apparaissent dans les filtres (en cache)
            'categories' => $catalogFilterService->getCategories(),
            'currentCategory' => $currentCategory,
        ]);
    }

    #[Route('/produits/{slug}', name: 'product_show', methods: ['GET'])]
    public function show(string $slug, ProductRepository $productRepository): Response
    {
        $product = $productRepository->findOneBy(['slug' => $slug, 'isActive' => true]);

        if (null === $product) {
            throw $this->createNotFoundException('Produit introuvable.');
        }

        return $this->render('product/show.html.twig', [
            'product' => $product,
        ]);
    }
}
