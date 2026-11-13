<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use App\Service\ApiTokenService;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\AccessToken\AccessTokenHandlerInterface;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;

/**
 * Retrouve le client à partir du jeton reçu dans l'en-tête Authorization: Bearer (pare-feu api_me, ADR 0021).
 * Une exception levée ici devient une réponse 401 problem+json (ApiAuthenticationHandler).
 */
final class ApiAccessTokenHandler implements AccessTokenHandlerInterface
{
    public function __construct(
        private readonly ApiTokenService $apiTokenService,
        // Le profileur (en développement seulement, d'où le « ? ») enregistre les en-têtes de chaque requête :
        // on le coupe pour que le jeton n'y soit jamais écrit
        #[Autowire(service: 'profiler')]
        private readonly ?Profiler $profiler = null,
    ) {
    }

    // #[\SensitiveParameter] : le jeton est masqué dans les traces d'exception (journaux, pages d'erreur)
    public function getUserBadgeFrom(#[\SensitiveParameter] string $accessToken): UserBadge
    {
        $this->profiler?->disable();

        // Un jeton révoqué a été supprimé : il est inconnu, comme un jeton inventé
        $apiToken = $this->apiTokenService->findByPlainToken($accessToken)
            ?? throw new CustomUserMessageAuthenticationException('Jeton d\'accès invalide ou révoqué.');

        if ($apiToken->isExpired()) {
            throw new CustomUserMessageAuthenticationException('Jeton d\'accès expiré : créez-en un nouveau dans « Mon compte ».');
        }

        $this->apiTokenService->markAsUsed($apiToken);
        $customer = $apiToken->getCustomer();

        // Le client est déjà chargé avec le jeton : inutile de le relire par son e-mail
        return new UserBadge($customer->getUserIdentifier(), static fn (): User => $customer);
    }
}
