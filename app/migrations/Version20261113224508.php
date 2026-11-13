<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Factures des commandes payées (ADR 0018).
 * Aucune clé étrangère en cascade : une facture ne se supprime jamais, et une commande facturée non plus.
 */
final class Version20261113224508 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Factures (ADR 0018) : tables invoice, invoice_line et compteur invoice_sequence';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE invoice (id INT AUTO_INCREMENT NOT NULL, number VARCHAR(20) NOT NULL, issued_at DATETIME NOT NULL, order_reference VARCHAR(20) NOT NULL, seller_name VARCHAR(100) NOT NULL, seller_address VARCHAR(255) NOT NULL, seller_postal_code VARCHAR(10) NOT NULL, seller_city VARCHAR(100) NOT NULL, seller_siret VARCHAR(14) NOT NULL, seller_vat_number VARCHAR(20) NOT NULL, customer_name VARCHAR(100) NOT NULL, customer_email VARCHAR(180) NOT NULL, billing_address VARCHAR(255) NOT NULL, billing_postal_code VARCHAR(10) NOT NULL, billing_city VARCHAR(100) NOT NULL, subtotal_amount INT NOT NULL, discount_amount INT NOT NULL, discount_code VARCHAR(30) DEFAULT NULL, total_excluding_tax INT NOT NULL, vat_rate INT NOT NULL, vat_amount INT NOT NULL, total_including_tax INT NOT NULL, order_id INT NOT NULL, UNIQUE INDEX UNIQ_9065174496901F54 (number), UNIQUE INDEX UNIQ_906517448D9F6D38 (order_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE invoice_line (id INT AUTO_INCREMENT NOT NULL, product_name VARCHAR(150) NOT NULL, variant_label VARCHAR(100) DEFAULT NULL, unit_price INT NOT NULL, quantity INT NOT NULL, total_amount INT NOT NULL, invoice_id INT NOT NULL, INDEX IDX_D3D1D6932989F1FD (invoice_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE invoice_sequence (fiscal_year INT NOT NULL, last_number INT NOT NULL, PRIMARY KEY (fiscal_year)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE invoice ADD CONSTRAINT FK_906517448D9F6D38 FOREIGN KEY (order_id) REFERENCES orders (id)');
        $this->addSql('ALTER TABLE invoice_line ADD CONSTRAINT FK_D3D1D6932989F1FD FOREIGN KEY (invoice_id) REFERENCES invoice (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE invoice DROP FOREIGN KEY FK_906517448D9F6D38');
        $this->addSql('ALTER TABLE invoice_line DROP FOREIGN KEY FK_D3D1D6932989F1FD');
        $this->addSql('DROP TABLE invoice');
        $this->addSql('DROP TABLE invoice_line');
        $this->addSql('DROP TABLE invoice_sequence');
    }
}
