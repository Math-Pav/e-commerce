<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Limite l'API à 60 requêtes par minute et par adresse IP (config/packages/rate_limiter.yaml, ADR 0020).
 *
 * Priorité 64 : avant le routage (32), pour compter aussi les requêtes vers une adresse inexistante.
 * Au-delà de la limite, l'exception devient une réponse 429 problem+json (ApiProblemListener)
 * avec l'en-tête Retry-After (secondes à attendre).
 */
#[AsEventListener(event: KernelEvents::REQUEST, priority: 64)]
final class ApiRateLimitListener
{
    public function __construct(
        private readonly RateLimiterFactoryInterface $apiLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();

        if (!$event->isMainRequest() || !str_starts_with($request->getPathInfo(), '/api/')) {
            return;
        }

        // getClientIp() : l'adresse de la connexion (REMOTE_ADDR). Aucun proxy de confiance n'est configuré,
        // donc un en-tête X-Forwarded-For envoyé par le client est ignoré et ne permet pas de contourner la limite.
        $limit = $this->apiLimiter->create($request->getClientIp())->consume();

        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(max(1, $limit->getRetryAfter()->getTimestamp() - time()), 'Trop de requêtes : la limite est de 60 par minute. Réessayez plus tard.');
        }
    }
}
