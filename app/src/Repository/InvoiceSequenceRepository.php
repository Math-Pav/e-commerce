<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\InvoiceSequence;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<InvoiceSequence>
 */
class InvoiceSequenceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InvoiceSequence::class);
    }

    /**
     * Réserve le numéro suivant de l'année. Doit être appelée dans la transaction qui enregistre la facture.
     *
     * - L'INSERT … ON DUPLICATE KEY UPDATE crée le compteur de l'année ou l'incrémente, en une seule
     *   instruction atomique. Il pose un verrou sur la ligne jusqu'à la fin de la transaction : un paiement
     *   simultané attend, puis incrémente la valeur à jour. Deux factures n'ont donc jamais le même numéro.
     * - Si la transaction échoue, l'incrément est annulé avec elle : aucun numéro n'est sauté.
     *
     * Requêtes SQL natives : DQL ne connaît pas ON DUPLICATE KEY UPDATE.
     * Les valeurs passent par des paramètres nommés, jamais par concaténation.
     */
    public function nextNumber(int $fiscalYear): int
    {
        $connection = $this->getEntityManager()->getConnection();

        if (!$connection->isTransactionActive()) {
            throw new \LogicException('Un numéro de facture se réserve dans la transaction qui enregistre la facture.');
        }

        $connection->executeStatement(
            'INSERT INTO invoice_sequence (fiscal_year, last_number) VALUES (:fiscalYear, 1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1',
            ['fiscalYear' => $fiscalYear],
        );

        // FOR UPDATE : lecture de la valeur la plus récente (la nôtre, puisque la ligne est verrouillée par nous)
        return (int) $connection->fetchOne(
            'SELECT last_number FROM invoice_sequence WHERE fiscal_year = :fiscalYear FOR UPDATE',
            ['fiscalYear' => $fiscalYear],
        );
    }
}
