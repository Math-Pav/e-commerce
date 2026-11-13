<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Order;
use App\Enum\OrderStatus;
use App\Repository\OrderRepository;
use App\Service\OrderService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\MapQueryParameter;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/commandes', name: 'admin_order_')]
#[IsGranted('ROLE_ADMIN')]
final class OrderController extends AbstractController
{
    private const STATUS_CSRF_TOKEN_ID = 'order-status';

    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderRepository $orderRepository,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(#[MapQueryParameter('statut')] ?string $statusValue = null): Response
    {
        // Un statut inconnu dans l'URL est ignoré : on affiche toutes les commandes
        $currentStatus = null !== $statusValue ? OrderStatus::tryFrom($statusValue) : null;

        return $this->render('admin/order/index.html.twig', [
            'orders' => $this->orderRepository->findAllWithCustomer($currentStatus),
            'statuses' => OrderStatus::cases(),
            'currentStatus' => $currentStatus,
        ]);
    }

    #[Route('/{reference}', name: 'show', methods: ['GET'])]
    public function show(string $reference): Response
    {
        return $this->render('admin/order/show.html.twig', [
            'order' => $this->findOrder($reference),
        ]);
    }

    #[Route('/{reference}/expedier', name: 'ship', methods: ['POST'])]
    public function ship(string $reference, Request $request): Response
    {
        $this->denyUnlessCsrfTokenValid($request);
        $order = $this->findOrder($reference);

        try {
            $this->orderService->ship($order);
            $this->addFlash('success', sprintf('La commande %s est expédiée.', $reference));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_order_show', ['reference' => $reference]);
    }

    #[Route('/{reference}/livrer', name: 'deliver', methods: ['POST'])]
    public function deliver(string $reference, Request $request): Response
    {
        $this->denyUnlessCsrfTokenValid($request);
        $order = $this->findOrder($reference);

        try {
            $this->orderService->deliver($order);
            $this->addFlash('success', sprintf('La commande %s est livrée.', $reference));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_order_show', ['reference' => $reference]);
    }

    #[Route('/{reference}/annuler', name: 'cancel', methods: ['POST'])]
    public function cancel(string $reference, Request $request): Response
    {
        $this->denyUnlessCsrfTokenValid($request);
        $order = $this->findOrder($reference);

        try {
            $this->orderService->cancel($order);
            $this->addFlash('success', sprintf('La commande %s est annulée et ses articles sont remis en stock.', $reference));
        } catch (\DomainException $exception) {
            $this->addFlash('error', $exception->getMessage());
        }

        return $this->redirectToRoute('admin_order_show', ['reference' => $reference]);
    }

    private function findOrder(string $reference): Order
    {
        $order = $this->orderRepository->findOneWithDetails($reference);

        if (null === $order) {
            throw $this->createNotFoundException('Commande introuvable.');
        }

        return $order;
    }

    private function denyUnlessCsrfTokenValid(Request $request): void
    {
        if (!$this->isCsrfTokenValid(self::STATUS_CSRF_TOKEN_ID, $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }
    }
}
