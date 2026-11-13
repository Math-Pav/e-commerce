<?php

declare(strict_types=1);

namespace App\Model;

/**
 * Calculs d'une pagination : nombre de pages et liste compacte des numéros à afficher.
 */
final class Pagination
{
    /**
     * Nombre de pages affichées de chaque côté de la page courante.
     */
    private const NEIGHBOURS = 1;

    public readonly int $lastPage;

    public function __construct(
        public readonly int $currentPage,
        public readonly int $perPage,
        public readonly int $totalItems,
    ) {
        // Aucun résultat : on garde une page (vide) pour afficher le message adapté
        $this->lastPage = max(1, (int) ceil($totalItems / $perPage));
    }

    public function isOutOfRange(): bool
    {
        return $this->currentPage > $this->lastPage;
    }

    public function hasPrevious(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNext(): bool
    {
        return $this->currentPage < $this->lastPage;
    }

    /**
     * Numéros de page à afficher : la première, la dernière, la courante et ses voisines.
     * null représente des points de suspension (« … ») à la place des pages sautées.
     * Exemple pour la page 6 sur 20 : [1, null, 5, 6, 7, null, 20].
     *
     * @return list<int|null>
     */
    public function pages(): array
    {
        $pages = [];
        $previous = 0;

        for ($page = 1; $page <= $this->lastPage; ++$page) {
            if (!$this->isDisplayed($page)) {
                continue;
            }

            if (2 === $page - $previous) {
                // Une seule page sautée : l'afficher prend autant de place que « … »
                $pages[] = $page - 1;
            } elseif ($page - $previous > 2) {
                $pages[] = null;
            }

            $pages[] = $page;
            $previous = $page;
        }

        return $pages;
    }

    private function isDisplayed(int $page): bool
    {
        return 1 === $page
            || $page === $this->lastPage
            || abs($page - $this->currentPage) <= self::NEIGHBOURS;
    }
}
