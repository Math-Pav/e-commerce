<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Form\ProductType;
use App\Model\ProductData;
use App\Repository\ProductRepository;
use App\Service\ProductService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/produits', name: 'admin_product_')]
#[IsGranted('ROLE_ADMIN')]
final class ProductController extends AbstractController
{
    private const STATUS_CSRF_TOKEN_ID = 'product-status';

    public function __construct(private readonly ProductService $productService)
    {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(ProductRepository $productRepository): Response
    {
        return $this->render('admin/product/index.html.twig', [
            'products' => $productRepository->findAllWithCategory(),
        ]);
    }

    #[Route('/nouveau', name: 'new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new ProductData();
        $form = $this->createForm(ProductType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $product = $this->productService->create($data);
            $this->addFlash('success', sprintf('Le produit « %s » a été créé.', $product->getName()));

            return $this->redirectToRoute('admin_product_index');
        }

        return $this->render('admin/product/new.html.twig', [
            'productForm' => $form,
        ]);
    }

    #[Route('/{id}/modifier', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Product $product, Request $request): Response
    {
        // Le formulaire modifie une copie des données : le produit n'est touché qu'une fois la saisie validée
        $data = ProductData::fromProduct($product);
        $form = $this->createForm(ProductType::class, $data, [
            'allow_image_removal' => null !== $product->getImageFilename(),
            'include_stock' => false,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->productService->update($product, $data);
            $this->addFlash('success', sprintf('Le produit « %s » a été modifié.', $product->getName()));

            return $this->redirectToRoute('admin_product_index');
        }

        return $this->render('admin/product/edit.html.twig', [
            'product' => $product,
            'productForm' => $form,
        ]);
    }

    #[Route('/{id}/activer', name: 'activate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activate(Product $product, Request $request): Response
    {
        $this->denyUnlessCsrfTokenValid($request);

        $this->productService->activate($product);
        $this->addFlash('success', sprintf('Le produit « %s » est en ligne.', $product->getName()));

        return $this->redirectToRoute('admin_product_index');
    }

    #[Route('/{id}/desactiver', name: 'deactivate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deactivate(Product $product, Request $request): Response
    {
        $this->denyUnlessCsrfTokenValid($request);

        $this->productService->deactivate($product);
        $this->addFlash('success', sprintf('Le produit « %s » est désactivé : il n\'apparaît plus dans le catalogue.', $product->getName()));

        return $this->redirectToRoute('admin_product_index');
    }

    private function denyUnlessCsrfTokenValid(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::STATUS_CSRF_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }
}
