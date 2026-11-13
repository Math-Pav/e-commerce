<?php

declare(strict_types=1);

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Sous /api/, toute erreur est renvoyée au format application/problem+json (RFC 9457)
 * au lieu de la page d'erreur HTML du site (ADR 0020).
 *
 * Seules les exceptions HTTP (404, 400, 429…), levées volontairement avec un message destiné au client,
 * exposent leur message. Une autre exception est une erreur 500 dont le détail reste dans les journaux.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ApiProblemListener
{
    public const CONTENT_TYPE = 'application/problem+json';

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $exception = $event->getThrowable();
        $isHttpException = $exception instanceof HttpExceptionInterface;
        $status = $isHttpException ? $exception->getStatusCode() : Response::HTTP_INTERNAL_SERVER_ERROR;

        $problem = [
            // about:blank : le code HTTP suffit à décrire le problème, pas de page de documentation dédiée
            'type' => 'about:blank',
            'title' => Response::$statusTexts[$status] ?? 'Error',
            'status' => $status,
        ];

        if ($isHttpException && '' !== $exception->getMessage()) {
            $problem['detail'] = $exception->getMessage();
        }

        $headers = $isHttpException ? $exception->getHeaders() : [];
        $headers['Content-Type'] = self::CONTENT_TYPE;

        $event->setResponse(new JsonResponse($problem, $status, $headers));
    }
}
