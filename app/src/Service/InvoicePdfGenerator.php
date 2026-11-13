<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Twig\Environment;

/**
 * Convertit le template Twig d'une facture en PDF avec dompdf.
 *
 * Le PDF n'est pas stocké : il est regénéré à chaque téléchargement à partir des données figées
 * de la facture, qui ne changent jamais (voir docs/adr/0018-factures.md).
 */
final class InvoicePdfGenerator
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    public function generate(Invoice $invoice): string
    {
        $html = $this->twig->render('invoice/pdf.html.twig', ['invoice' => $invoice]);

        $options = new Options();
        // Aucune ressource distante ni code exécuté : le HTML ne contient que des données de la facture
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        // Police fournie avec dompdf qui contient tous les caractères utiles (€, accents, tirets)
        $options->setDefaultFont('DejaVu Sans');

        // Dompdf n'est pas un service mais un objet de travail, propre à chaque document : on le crée ici
        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
