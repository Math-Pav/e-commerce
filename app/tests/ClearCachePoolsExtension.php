<?php

declare(strict_types=1);

namespace App\Tests;

use PHPUnit\Event\Test\PreparationStarted;
use PHPUnit\Event\Test\PreparationStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Vide le cache applicatif (fichiers de var/share/test/pools/app) avant chaque test.
 *
 * DAMA annule les écritures en base à la fin de chaque test, mais pas le cache : sans ce nettoyage,
 * un test pourrait lire des statistiques ou des catégories mises en cache par le test précédent.
 */
final class ClearCachePoolsExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        $facade->registerSubscriber(new class implements PreparationStartedSubscriber {
            public function notify(PreparationStarted $event): void
            {
                (new Filesystem())->remove(\dirname(__DIR__).'/var/share/test/pools/app');
            }
        });
    }
}
