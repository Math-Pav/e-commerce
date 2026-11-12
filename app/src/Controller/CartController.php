<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Product;
use App\Exception\InsufficientStockException;
use App\Service\CartService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/panier')]
final class CartController extends AbstractController
{
    private const CSRF_TOKEN_ID = 'cart';

    public function __construct(private readonly CartService $cartService)
    {
    }

    #[Route('', name: 'cart_show', methods: ['GET'])]
    public function show(): Response
    {
        return $this->render('cart/show.html.twig', [
            'cart' => $this->cartService->getCart(),
        ]);
    }

    #[Route('/ajouter/{id}', name: 'cart_add', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function add(Product $product, Request $request): Response
    {
        $this->denyUnlessCsrfTokenValid($request);

        if (!$product->isActive()) {
            throw $this->createNotFoundException('Produit introuvable.');
        }

        $quantity = $request->getPayload()->getInt('quantity', 1);
        if ($quantity < 1) {
            throw new BadRequestHttpException('La quantité doit être au moins égale à 1.');
        }

        try {
            $this->cartService->add($product, $quantity);
        } catch (InsufficientStockException $exception) {
            $this->addFlash('error', $exception->getMessage());

            return $this->redirectToRoute('product_show', ['slug' => $product->getSlug()]);
        }

        $this->addFlash('success', sprintf('« %s » a été ajouté au panier.', $product->getName()));

        return $this->redirectToRoute('cart_show');
    }

    #[Route('/retirer/{id}', name: 'cart_remove', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remove(Product $product, Request $request): Response
    {
        $this->denyUnlessCsrfTokenValid($request);

        $this->cartService->remove($product);
        $this->addFlash('success', sprintf('« %s » a été retiré du panier.', $product->getName()));

        return $this->redirectToRoute('cart_show');
    }

    private function denyUnlessCsrfTokenValid(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }
}