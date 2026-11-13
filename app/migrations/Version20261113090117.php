<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113090117 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Ajoute les dates d'expédition, de livraison et d'annulation des commandes";
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE orders ADD shipped_at DATETIME DEFAULT NULL, ADD delivered_at DATETIME DEFAULT NULL, ADD cancelled_at DATETIME DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE orders DROP shipped_at, DROP delivered_at, DROP cancelled_at');
    }
}
