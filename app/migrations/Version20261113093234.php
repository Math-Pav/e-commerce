<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113093234 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute la photo facultative des produits (product.image_filename)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD image_filename VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP image_filename');
    }
}
