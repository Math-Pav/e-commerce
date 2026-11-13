<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Variantes de produits (ADR 0017) : le stock passe du produit à ses variantes.
 * Chaque produit existant reçoit une variante par défaut qui reprend son stock.
 */
final class Version20261113222104 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute les variantes de produits et y déplace le stock des produits';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE product_variant (id INT AUTO_INCREMENT NOT NULL, label VARCHAR(100) DEFAULT NULL, sku VARCHAR(64) NOT NULL, price INT DEFAULT NULL, stock INT DEFAULT 0 NOT NULL, is_active TINYINT DEFAULT 1 NOT NULL, product_id INT NOT NULL, UNIQUE INDEX UNIQ_209AA41DF9038C4 (sku), INDEX IDX_209AA41D4584665A (product_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE product_variant ADD CONSTRAINT FK_209AA41D4584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');

        // Une variante par défaut par produit : sans libellé, sans prix propre (celui du produit), avec le stock du produit
        $this->addSql("INSERT INTO product_variant (product_id, label, sku, price, stock, is_active) SELECT id, NULL, CONCAT('PRD-', LPAD(id, 5, '0')), NULL, stock, 1 FROM product ORDER BY id");

        $this->addSql('ALTER TABLE order_item ADD variant_label VARCHAR(100) DEFAULT NULL, ADD variant_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE order_item ADD CONSTRAINT FK_52EA1F093B69A9AF FOREIGN KEY (variant_id) REFERENCES product_variant (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_52EA1F093B69A9AF ON order_item (variant_id)');
        // Les lignes de commande existantes pointent vers la variante par défaut de leur produit (la seule à ce stade) :
        // une commande en attente reste annulable, son stock revient sur la bonne variante
        $this->addSql('UPDATE order_item oi JOIN product_variant v ON v.product_id = oi.product_id SET oi.variant_id = v.id');

        // Le tri par prix utilise désormais le prix des variantes : cet index ne sert plus
        $this->addSql('DROP INDEX idx_product_active_price ON product');
        $this->addSql('ALTER TABLE product DROP stock');
    }

    public function down(Schema $schema): void
    {
        $productsWithSeveralVariants = (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM (SELECT product_id FROM product_variant GROUP BY product_id HAVING COUNT(*) > 1) AS t',
        );
        $this->warnIf(
            $productsWithSeveralVariants > 0,
            sprintf('%d produit(s) ont plusieurs variantes : leurs stocks sont additionnés, leurs libellés, références et prix propres sont perdus.', $productsWithSeveralVariants),
        );

        $this->addSql('ALTER TABLE product ADD stock INT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_product_active_price ON product (is_active, price)');
        // Le produit reprend la somme des stocks de ses variantes : aucun article n'est perdu
        $this->addSql('UPDATE product p SET p.stock = (SELECT COALESCE(SUM(v.stock), 0) FROM product_variant v WHERE v.product_id = p.id)');

        $this->addSql('ALTER TABLE order_item DROP FOREIGN KEY FK_52EA1F093B69A9AF');
        $this->addSql('DROP INDEX IDX_52EA1F093B69A9AF ON order_item');
        $this->addSql('ALTER TABLE order_item DROP variant_label, DROP variant_id');
        $this->addSql('ALTER TABLE product_variant DROP FOREIGN KEY FK_209AA41D4584665A');
        $this->addSql('DROP TABLE product_variant');
    }
}
