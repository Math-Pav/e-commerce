<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\Api\OrderOutputFactory;
use App\Entity\User;
use App\Repository\OrderRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Commandes du client authentifié par son jeton d'accès (pare-feu api_me, docs/api.md, ADR 0021).
 */
#[IsGranted('ROLE_USER')]
#[Route('/api/v1/me', name: 'api_me_', methods: ['GET'], format: 'json')]
final class CustomerOrderController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly OrderOutputFactory $outputFactory,
    ) {
    }

    #[Route('/orders', name: 'order_index')]
    public function orders(#[CurrentUser] User $user): JsonResponse
    {
        return $this->json(['data' => $this->outputFactory->orders($this->orderRepository->findByCustomerWithItems($user))]);
    }

    #[Route('/orders/{reference}', name: 'order_show')]
    public function order(string $reference, #[CurrentUser] User $user): JsonResponse
    {
        // Recherche limitée aux commandes du client : celle d'un autre client donne une 404, comme une commande inexistante (IDOR)
        $order = $this->orderRepository->findOneBy(['reference' => $reference, 'customer' => $user])
            ?? throw new NotFoundHttpException(sprintf('La commande « %s » n\'existe pas.', $reference));

        return $this->json(['data' => $this->outputFactory->orderDetail($order)]);
    }
}
