<?php

declare(strict_types=1);

namespace App\Api\Output;

/**
 * Une commande dans la liste « mes commandes » de l'API (ADR 0021).
 * La référence sert d'identifiant public, jamais l'id en base.
 */
final class OrderOutput
{
    public function __construct(
        public readonly string $reference,
        public readonly string $status,
        public readonly string $statusLabel,
        // Date au format ISO 8601 avec le fuseau horaire (ex. 2026-11-14T10:30:00+01:00)
        public readonly string $createdAt,
        public readonly Price $total,
        public readonly int $itemCount,
    ) {
    }
}
