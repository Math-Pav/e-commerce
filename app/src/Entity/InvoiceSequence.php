<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\InvoiceSequenceRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Compteur des numéros de facture, une ligne par année (voir docs/adr/0018-factures.md).
 * Il n'est lu et incrémenté que par InvoiceSequenceRepository::nextNumber(), sous verrou.
 */
#[ORM\Entity(repositoryClass: InvoiceSequenceRepository::class)]
class InvoiceSequence
{
    #[ORM\Id]
    #[ORM\Column]
    private int $fiscalYear;

    // Dernier numéro attribué dans l'année
    #[ORM\Column]
    private int $lastNumber;

    public function __construct(int $fiscalYear, int $lastNumber)
    {
        $this->fiscalYear = $fiscalYear;
        $this->lastNumber = $lastNumber;
    }

    public function getFiscalYear(): int
    {
        return $this->fiscalYear;
    }

    public function getLastNumber(): int
    {
        return $this->lastNumber;
    }
}
