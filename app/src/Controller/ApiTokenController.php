<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\User;
use App\Form\ApiTokenType;
use App\Model\ApiTokenData;
use App\Repository\ApiTokenRepository;
use App\Service\ApiTokenService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Jetons d'accès personnels à l'API, gérés par le client dans « Mon compte » (ADR 0021).
 */
#[IsGranted('ROLE_USER')]
#[Route('/mon-compte/jetons-api')]
final class ApiTokenController extends AbstractController
{
    public function __construct(
        private readonly ApiTokenService $apiTokenService,
        private readonly ApiTokenRepository $apiTokenRepository,
    ) {
    }

    #[Route('', name: 'account_api_token_index', methods: ['GET', 'POST'])]
    public function index(Request $request, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(ApiTokenType::class, new ApiTokenData());
        $form->handleRequest($request);
        $plainToken = null;

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $plainToken = $this->apiTokenService->create($user, $form->getData()->name);
                $form = $this->createForm(ApiTokenType::class, new ApiTokenData());
            } catch (\DomainException $exception) {
                $this->addFlash('error', $exception->getMessage());

                return $this->redirectToRoute('account_api_token_index');
            }
        }

        // Pas de redirection après la création : le jeton en clair est affiché dans cette réponse,
        // sans passer par la session. no-store : le navigateur ne garde pas la page en cache.
        $response = $this->render('account/api_tokens.html.twig', [
            'form' => $form,
            'plainToken' => $plainToken,
            'apiTokens' => $this->apiTokenRepository->findByCustomer($user),
            'maxActiveTokens' => ApiTokenService::MAX_ACTIVE_TOKENS,
            'lifetimeDays' => ApiTokenService::LIFETIME_DAYS,
        ]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    #[Route('/{id}/revoquer', name: 'account_api_token_revoke', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function revoke(int $id, Request $request, #[CurrentUser] User $user): Response
    {
        if (!$this->isCsrfTokenValid('revoke-api-token', $request->getPayload()->getString('_token'))) {
            throw new AccessDeniedHttpException('Jeton CSRF invalide.');
        }

        // Recherche limitée aux jetons du client connecté : celui d'un autre client donne une 404 (IDOR)
        $apiToken = $this->apiTokenRepository->findOneBy(['id' => $id, 'customer' => $user])
            ?? throw $this->createNotFoundException('Jeton introuvable.');

        $this->apiTokenService->revoke($apiToken);
        $this->addFlash('success', sprintf('Le jeton « %s » a été révoqué.', $apiToken->getName()));

        return $this->redirectToRoute('account_api_token_index');
    }
}
