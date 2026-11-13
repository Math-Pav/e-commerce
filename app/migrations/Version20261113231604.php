<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261113231604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return "Jetons d'accès personnels à l'API (ADR 0021) : table api_token";
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE api_token (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(50) NOT NULL, token_hash VARCHAR(64) NOT NULL, token_prefix VARCHAR(8) NOT NULL, created_at DATETIME NOT NULL, last_used_at DATETIME DEFAULT NULL, expires_at DATETIME NOT NULL, customer_id INT NOT NULL, UNIQUE INDEX UNIQ_7BA2F5EBB3BC57DA (token_hash), INDEX IDX_7BA2F5EB9395C3F3 (customer_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE api_token ADD CONSTRAINT FK_7BA2F5EB9395C3F3 FOREIGN KEY (customer_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE api_token DROP FOREIGN KEY FK_7BA2F5EB9395C3F3');
        $this->addSql('DROP TABLE api_token');
    }
}
