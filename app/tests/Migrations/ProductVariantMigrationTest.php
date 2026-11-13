<?php

declare(strict_types=1);

namespace App\Tests\Migrations;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\Migrations\Configuration\Configuration;
use Doctrine\Migrations\Configuration\Connection\ExistingConnection;
use Doctrine\Migrations\Configuration\Migration\ExistingConfiguration;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Metadata\Storage\TableMetadataStorageConfiguration;
use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Version;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Rejoue la migration des variantes (ADR 0017) sur une base temporaire, créée puis supprimée par le test.
 *
 * Pourquoi pas la base de test habituelle ? Chaque test y tourne dans une transaction annulée à la fin
 * (dama/doctrine-test-bundle). Or MariaDB valide automatiquement la transaction en cours à chaque
 * CREATE ou ALTER TABLE : la migration modifierait réellement les données des autres tests.
 */
final class ProductVariantMigrationTest extends KernelTestCase
{
    private const PREVIOUS_VERSION = 'DoctrineMigrations\Version20261113220411';

    private const VARIANTS_VERSION = 'DoctrineMigrations\Version20261113222104';

    private Connection $server;

    private Connection $connection;

    private string $databaseName;

    protected function setUp(): void
    {
        self::bootKernel();
        /** @var Connection $testConnection */
        $testConnection = static::getContainer()->get('doctrine.dbal.default_connection');
        $params = $testConnection->getParams();
        $this->databaseName = $params['dbname'].'_migration';

        // Connexion au serveur sans base, le temps de créer la base temporaire
        $serverParams = $params;
        unset($serverParams['dbname']);
        $this->server = DriverManager::getConnection($serverParams);
        $this->server->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', $this->databaseName));
        $this->server->executeStatement(sprintf('CREATE DATABASE `%s`', $this->databaseName));

        $this->connection = DriverManager::getConnection(['dbname' => $this->databaseName] + $params);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
        $this->server->executeStatement(sprintf('DROP DATABASE IF EXISTS `%s`', $this->databaseName));
        $this->server->close();

        parent::tearDown();
    }

    public function testExistingStocksMoveToDefaultVariantsAndComeBack(): void
    {
        $this->migrateTo(self::PREVIOUS_VERSION);
        $this->connection->executeStatement("INSERT INTO category (id, name, slug) VALUES (1, 'Mugs', 'mugs')");
        $this->connection->executeStatement(
            "INSERT INTO product (id, category_id, name, slug, price, stock, is_active, created_at) VALUES
                (7, 1, 'Mug bleu', 'mug-bleu', 1290, 12, 1, NOW()),
                (9, 1, 'Mug épuisé', 'mug-epuise', 990, 0, 0, NOW())",
        );

        $this->migrateTo(self::VARIANTS_VERSION);

        self::assertSame([
            ['product_id' => 7, 'label' => null, 'sku' => 'PRD-00007', 'price' => null, 'stock' => 12, 'is_active' => 1],
            ['product_id' => 9, 'label' => null, 'sku' => 'PRD-00009', 'price' => null, 'stock' => 0, 'is_active' => 1],
        ], $this->fetchVariants());

        // Retour en arrière : le stock revient sur le produit
        $this->migrateTo(self::PREVIOUS_VERSION);
        self::assertSame(
            ['7' => 12, '9' => 0],
            array_map(intval(...), $this->connection->fetchAllKeyValue('SELECT id, stock FROM product ORDER BY id')),
        );

        // Et la migration peut être rejouée
        $this->migrateTo(self::VARIANTS_VERSION);
        self::assertCount(2, $this->fetchVariants());
    }

    /**
     * Équivalent de « bin/console doctrine:migrations:migrate <version> » sur la base temporaire.
     * Une migration déjà exécutée est « gelée » : comme la console, on recrée les objets à chaque appel.
     */
    private function migrateTo(string $version): void
    {
        $configuration = new Configuration();
        $configuration->addMigrationsDirectory('DoctrineMigrations', dirname(__DIR__, 2).'/migrations');
        $configuration->setMetadataStorageConfiguration(new TableMetadataStorageConfiguration());

        $migrations = DependencyFactory::fromConnection(
            new ExistingConfiguration($configuration),
            new ExistingConnection($this->connection),
        );
        $migrations->getMetadataStorage()->ensureInitialized();

        $plan = $migrations->getMigrationPlanCalculator()->getPlanUntilVersion(new Version($version));
        $migrations->getMigrator()->migrate($plan, new MigratorConfiguration());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchVariants(): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT product_id, label, sku, price, stock, is_active FROM product_variant ORDER BY product_id',
        );

        // Les entiers arrivent parfois sous forme de chaînes selon le pilote : on les normalise
        return array_map(static fn (array $row): array => [
            'product_id' => (int) $row['product_id'],
            'label' => $row['label'],
            'sku' => $row['sku'],
            'price' => null === $row['price'] ? null : (int) $row['price'],
            'stock' => (int) $row['stock'],
            'is_active' => (int) $row['is_active'],
        ], $rows);
    }
}
