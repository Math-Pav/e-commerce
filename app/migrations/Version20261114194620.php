<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261114194620 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Verrouillage optimiste des variantes (ADR 0023) : colonne product_variant.version';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_variant ADD version INT DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product_variant DROP version');
    }
}
