<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261113220411 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les codes promo et la remise des commandes';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE promo_code (id INT AUTO_INCREMENT NOT NULL, code VARCHAR(30) NOT NULL, discount_type VARCHAR(20) NOT NULL, value INT NOT NULL, starts_at DATETIME DEFAULT NULL, ends_at DATETIME DEFAULT NULL, max_usages INT DEFAULT NULL, usage_count INT DEFAULT 0 NOT NULL, minimum_amount INT DEFAULT NULL, is_active TINYINT DEFAULT 1 NOT NULL, UNIQUE INDEX UNIQ_3D8C939E77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE orders ADD subtotal_amount INT NOT NULL, ADD discount_amount INT DEFAULT 0 NOT NULL, ADD discount_code VARCHAR(30) DEFAULT NULL, ADD promo_code_id INT DEFAULT NULL');
        // Commandes existantes : aucune remise, le sous-total est donc égal au total
        $this->addSql('UPDATE orders SET subtotal_amount = total_amount');
        $this->addSql('ALTER TABLE orders ADD CONSTRAINT FK_E52FFDEE2FAE4625 FOREIGN KEY (promo_code_id) REFERENCES promo_code (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_E52FFDEE2FAE4625 ON orders (promo_code_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE orders DROP FOREIGN KEY FK_E52FFDEE2FAE4625');
        $this->addSql('DROP INDEX IDX_E52FFDEE2FAE4625 ON orders');
        $this->addSql('ALTER TABLE orders DROP subtotal_amount, DROP discount_amount, DROP discount_code, DROP promo_code_id');
        $this->addSql('DROP TABLE promo_code');
    }
}
