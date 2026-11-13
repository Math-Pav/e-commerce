<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Form\ProductVariantType;
use App\Model\ProductVariantData;
use App\Service\ProductVariantService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin', name: 'admin_product_variant_')]
#[IsGranted('ROLE_ADMIN')]
final class ProductVariantController extends AbstractController
{
    private const STATUS_CSRF_TOKEN_ID = 'product-variant-status';

    public function __construct(private readonly ProductVariantService $variantService)
    {
    }

    #[Route('/produits/{id}/variantes', name: 'index', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function index(Product $product): Response
    {
        return $this->render('admin/product_variant/index.html.twig', [
            'product' => $product,
        ]);
    }

    #[Route('/produits/{id}/variantes/nouvelle', name: 'new', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function new(Product $product, Request $request): Response
    {
        $data = ProductVariantData::forProduct($product);
        $form = $this->createForm(ProductVariantType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $variant = $this->variantService->create($product, $data);
            $this->addFlash('success', sprintf('La variante « %s » a été ajoutée.', $variant->getName()));

            return $this->redirectToRoute('admin_product_variant_index', ['id' => $product->getId()]);
        }

        return $this->render('admin/product_variant/new.html.twig', [
            'product' => $product,
            'variantForm' => $form,
        ]);
    }

    #[Route('/variantes/{id}/modifier', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(ProductVariant $variant, Request $request): Response
    {
        $data = ProductVariantData::fromVariant($variant);
        $form = $this->createForm(ProductVariantType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->variantService->update($variant, $data);
            $this->addFlash('success', sprintf('La variante « %s » a été modifiée.', $variant->getName()));

            return $this->redirectToProduct($variant);
        }

        return $this->render('admin/product_variant/edit.html.twig', [
            'variant' => $variant,
            'variantForm' => $form,
        ]);
    }

    #[Route('/variantes/{id}/activer', name: 'activate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function activate(ProductVariant $variant, Request $request): Response
    {
        $this->denyUnlessCsrfTokenValid($request);

        $this->variantService->activate($variant);
        $this->addFlash('success', sprintf('La variante « %s » est proposée aux clients.', $variant->getName()));

        return $this->redirectToProduct($variant);
    }

    #[Route('/variantes/{id}/desactiver', name: 'deactivate', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deactivate(ProductVariant $variant, Request $request): Response
    {
        $this->denyUnlessCsrfTokenValid($request);

        $this->variantService->deactivate($variant);
        $this->addFlash('success', sprintf('La variante « %s » est désactivée : elle n\'est plus proposée aux clients.', $variant->getName()));

        return $this->redirectToProduct($variant);
    }

    private function redirectToProduct(ProductVariant $variant): Response
    {
        return $this->redirectToRoute('admin_product_variant_index', ['id' => $variant->getProduct()?->getId()]);
    }

    private function denyUnlessCsrfTokenValid(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::STATUS_CSRF_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }
}
