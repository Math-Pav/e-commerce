<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use App\Repository\CategoryRepository;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;

final class ProductController extends AbstractController
{
    #[Route('/', name: 'home', methods: ['GET'])]
    #[Route('/produits', name: 'product_index', methods: ['GET'])]
    public function index(
        ProductRepository $productRepository,
        CategoryRepository $categoryRepository,
        #[MapQueryParameter('categorie')] ?string $categorySlug = null,
    ): Response {
        $currentCategory = $categorySlug !== null
            ? $categoryRepository->findOneBy(['slug' => $categorySlug])
            : null;

        return $this->render('product/index.html.twig', [
            'products' => $productRepository->findActive($currentCategory),
            'categories' => $categoryRepository->findBy([], ['name' => 'ASC']),
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