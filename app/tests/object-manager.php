<?php

declare(strict_types=1);

// Fournit l'EntityManager à PHPStan (extension phpstan-doctrine) pour qu'il
// connaisse le mapping des entités : types des colonnes, relations, DQL.
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

(new Dotenv())->bootEnv(dirname(__DIR__).'/.env');

$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

return $kernel->getContainer()->get('doctrine')->getManager();
