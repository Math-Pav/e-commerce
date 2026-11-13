<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\Order;
use App\Entity\User;
use App\Exception\InsufficientStockException;
use App\Exception\PaymentFailedException;
use App\Form\ShippingAddressType;
use App\Model\ShippingAddress;
use App\Repository\OrderRepository;
use App\Service\CartService;
use App\Service\OrderService;
use App\Service\PaymentService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class OrderController extends AbstractController
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly OrderService $orderService,
        private readonly OrderRepository $orderRepository,
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

        $form = $this->createForm(ShippingAddressType::class, ShippingAddress::fromCustomer($user));
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $order = $this->orderService->placeOrder($user, $cart, $form->getData());
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

    #[Route('/mes-commandes', name: 'order_index', methods: ['GET'])]
    public function index(#[CurrentUser] User $user): Response
    {
        return $this->render('order/index.html.twig', [
            'orders' => $this->orderRepository->findBy(['customer' => $user], ['createdAt' => 'DESC']),
        ]);
    }

    #[Route('/mes-commandes/{reference}', name: 'order_show', methods: ['GET'])]
    public function show(string $reference, #[CurrentUser] User $user): Response
    {
        return $this->render('order/show.html.twig', [
            'order' => $this->findCustomerOrder($reference, $user),
        ]);
    }

    #[Route('/mes-commandes/{reference}/payer', name: 'order_pay', methods: ['POST'])]
    public function pay(string $reference, #[CurrentUser] User $user, Request $request, PaymentService $paymentService): Response
    {
        if (!$this->isCsrfTokenValid('pay-order', $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }

        $order = $this->findCustomerOrder($reference, $user);

        try {
            $paymentService->pay($order);
            $this->addFlash('success', 'Merci ! Votre paiement a bien été accepté.');
        } catch (PaymentFailedException|\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('order_show', ['reference' => $reference]);
    }

    #[Route('/mes-commandes/{reference}/annuler', name: 'order_cancel', methods: ['POST'])]
    public function cancel(string $reference, #[CurrentUser] User $user, Request $request): Response
    {
        if (!$this->isCsrfTokenValid('cancel-order', $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }

        $order = $this->findCustomerOrder($reference, $user);

        try {
            $this->orderService->cancel($order);
            $this->addFlash('success', sprintf('Votre commande %s a bien été annulée.', $reference));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('order_show', ['reference' => $reference]);
    }

    /**
     * Ne renvoie que les commandes du client connecté (protection contre l'IDOR).
     */
    private function findCustomerOrder(string $reference, User $user): Order
    {
        $order = $this->orderRepository->findOneBy(['reference' => $reference, 'customer' => $user]);

        if ($order === null) {
            throw $this->createNotFoundException('Commande introuvable.');
        }

        return $order;
    }
}