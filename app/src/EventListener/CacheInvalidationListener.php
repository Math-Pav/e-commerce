<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Category;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Service\CatalogFilterService;
use App\Service\DashboardService;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Events;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

/**
 * Invalide le cache applicatif quand des données dont il dépend sont enregistrées (ADR 0019).
 *
 * Écouteur Doctrine plutôt qu'appels dans les services : toute écriture passant par l'ORM est vue
 * (services, fixtures, code futur), sans risque d'oublier un chemin.
 */
#[AsDoctrineListener(event: Events::onFlush)]
#[AsDoctrineListener(event: Events::postFlush)]
final class CacheInvalidationListener
{
    /**
     * Étiquettes à invalider selon la classe de l'entité écrite.
     * Un produit touche aussi le tableau de bord : son nom apparaît dans les meilleures ventes.
     */
    private const TAGS_BY_ENTITY = [
        Order::class => [DashboardService::CACHE_TAG],
        OrderItem::class => [DashboardService::CACHE_TAG],
        Product::class => [DashboardService::CACHE_TAG, CatalogFilterService::CACHE_TAG],
        Category::class => [CatalogFilterService::CACHE_TAG],
    ];

    /** @var array<string, true> étiquettes repérées pendant le flush en cours */
    private array $pendingTags = [];

    /** @var array<string, true> étiquettes invalidées pendant cette requête ou cette commande */
    private array $invalidatedTags = [];

    public function __construct(private readonly TagAwareCacheInterface $computedCache)
    {
    }

    /**
     * Juste avant l'écriture : on repère les entités insérées, modifiées ou supprimées.
     */
    public function onFlush(OnFlushEventArgs $args): void
    {
        $unitOfWork = $args->getObjectManager()->getUnitOfWork();
        $entities = [
            ...$unitOfWork->getScheduledEntityInsertions(),
            ...$unitOfWork->getScheduledEntityUpdates(),
            ...$unitOfWork->getScheduledEntityDeletions(),
        ];

        foreach ($entities as $entity) {
            foreach (self::TAGS_BY_ENTITY as $class => $tags) {
                if ($entity instanceof $class) {
                    foreach ($tags as $tag) {
                        $this->pendingTags[$tag] = true;
                    }
                }
            }
        }
    }

    /**
     * Après l'écriture : on invalide tout de suite, pour que la suite de la requête lise des données à jour.
     */
    public function postFlush(PostFlushEventArgs $args): void
    {
        if ([] === $this->pendingTags) {
            return;
        }

        $this->computedCache->invalidateTags(array_keys($this->pendingTags));
        $this->invalidatedTags += $this->pendingTags;
        $this->pendingTags = [];
    }

    /**
     * Seconde invalidation une fois la réponse envoyée : le flush a pu avoir lieu dans une transaction
     * (commande, paiement, annulation). Entre ce flush et le COMMIT, une autre requête a pu remettre en cache
     * les anciennes valeurs ; on les efface maintenant que la transaction est validée.
     */
    #[AsEventListener(event: KernelEvents::TERMINATE)]
    #[AsEventListener(event: ConsoleEvents::TERMINATE)]
    public function onTerminate(): void
    {
        if ([] === $this->invalidatedTags) {
            return;
        }

        $this->computedCache->invalidateTags(array_keys($this->invalidatedTags));
        $this->invalidatedTags = [];
    }
}
