<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Index composites des tris du catalogue (ADR 0011).
 */
final class Version20261113095349 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les index (is_active, name), (is_active, price) et (is_active, created_at) sur product';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_product_active_name ON product (is_active, name)');
        $this->addSql('CREATE INDEX idx_product_active_price ON product (is_active, price)');
        $this->addSql('CREATE INDEX idx_product_active_created_at ON product (is_active, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_product_active_name ON product');
        $this->addSql('DROP INDEX idx_product_active_price ON product');
        $this->addSql('DROP INDEX idx_product_active_created_at ON product');
    }
}
