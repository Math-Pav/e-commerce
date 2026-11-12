<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Exception\InsufficientStockException;
use App\Form\ShippingAddressType;
use App\Model\ShippingAddress;
use App\Repository\OrderRepository;
use App\Service\CartService;
use App\Service\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class OrderController extends AbstractController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly OrderService $orderService,
    ) {
    }

    #[Route('/commande', name: 'order_checkout', methods: ['GET', 'POST'])]
    public function checkout(Request $request, #[CurrentUser] User $user): Response
    {
        $cart = $this->cartService->getCart();

        if ($cart->isEmpty()) {
            $this->addFlash('error', 'Votre panier est vide.');

            return $this->redirectToRoute('cart_show');
        }

        $address = new ShippingAddress();
        $address->fullName = sprintf('%s %s', $user->getFirstName(), $user->getLastName());

        $form = $this->createForm(ShippingAddressType::class, $address);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $order = $this->orderService->placeOrder($user, $cart, $address);
            } catch (InsufficientStockException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('cart_show');
            }

            $this->cartService->clear();
            $this->addFlash('success', sprintf('Votre commande %s a bien été enregistrée.', $order->getReference()));

            return $this->redirectToRoute('order_show', ['reference' => $order->getReference()]);
        }

        return $this->render('order/checkout.html.twig', [
            'cart' => $cart,
            'checkoutForm' => $form,
        ]);
    }

    #[Route('/mes-commandes/{reference}', name: 'order_show', methods: ['GET'])]
    public function show(string $reference, #[CurrentUser] User $user, OrderRepository $orderRepository): Response
    {
        // On cherche la commande ET son propriétaire : impossible d'afficher la commande d'un autre client
        $order = $orderRepository->findOneBy(['reference' => $reference, 'customer' => $user]);

        if ($order === null) {
            throw $this->createNotFoundException('Commande introuvable.');
        }

        return $this->render('order/show.html.twig', [
            'order' => $order,
        ]);
    }
}