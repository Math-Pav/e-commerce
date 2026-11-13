<?php

declare(strict_types=1);

namespace App\Security;

use App\EventListener\ApiProblemListener;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationFailureHandlerInterface;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Réponses 401 problem+json du pare-feu api_me (ADR 0021) :
 * - start() : aucun jeton envoyé (point d'entrée du pare-feu) ;
 * - onAuthenticationFailure() : jeton inconnu, révoqué ou expiré.
 * L'en-tête WWW-Authenticate suit la RFC 6750 (jetons « Bearer »).
 */
final class ApiAuthenticationHandler implements AuthenticationEntryPointInterface, AuthenticationFailureHandlerInterface
{
    private const REALM = 'api';

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return ApiProblemListener::createResponse(
            Response::HTTP_UNAUTHORIZED,
            'Authentification requise : envoyez votre jeton dans l\'en-tête « Authorization: Bearer <jeton> ».',
            ['WWW-Authenticate' => sprintf('Bearer realm="%s"', self::REALM)],
        );
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        // Seuls nos messages (ApiAccessTokenHandler) sont montrés au client
        $detail = $exception instanceof CustomUserMessageAuthenticationException
            ? $exception->getMessageKey()
            : 'Jeton d\'accès invalide.';

        return ApiProblemListener::createResponse(
            Response::HTTP_UNAUTHORIZED,
            $detail,
            ['WWW-Authenticate' => sprintf('Bearer realm="%s", error="invalid_token"', self::REALM)],
        );
    }
}
