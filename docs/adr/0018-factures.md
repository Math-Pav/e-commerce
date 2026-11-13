# ADR 0018 : Factures

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

Une commande payée n'avait aucun document comptable. Il faut une facture PDF pour chaque commande payée, téléchargeable par le client et par l'administration, et jointe à l'e-mail de confirmation du paiement. Les prix du site sont TTC, avec une TVA unique de 20 %. Projet d'apprentissage : les mentions légales sont simplifiées (vendeur, SIRET et numéro de TVA fictifs), mais la logique doit être réaliste :

- une facture est **immuable** : elle ne se modifie ni ne se supprime ;
- son numéro est **unique, chronologique et sans trou** (`FA-2026-000001`), même si deux paiements ont lieu au même instant.

## Options étudiées

### Contenu de la facture

1. **Lire la commande** au moment d'afficher la facture : un changement de nom du client ou de la configuration du vendeur modifierait une facture déjà émise.
2. **Copier dans une entité `Invoice`** tout ce qui est imprimé (vendeur, client, adresse, lignes, remise, totaux HT / TVA / TTC), comme `OrderItem` copie le prix.

### Numérotation

1. **Identifiant auto-incrémenté** de la table : une transaction annulée consomme quand même une valeur (trou), et la numérotation ne repart pas chaque année.
2. **`MAX(number) + 1`** sur les factures : deux paiements simultanés lisent le même maximum et obtiennent le même numéro, sauf à verrouiller toute la table.
3. **Compteur dans une table `invoice_sequence`** (une ligne par année), incrémenté par `INSERT … ON DUPLICATE KEY UPDATE last_number = last_number + 1` dans la transaction du paiement : l'instruction est atomique et verrouille la ligne jusqu'au `COMMIT` ; le second paiement attend, puis incrémente la valeur à jour. Si la transaction échoue, l'incrément est annulé avec elle.

### Calcul du HT et de la TVA

1. **Par ligne**, puis addition : chaque arrondi peut décaler le total HT de quelques centimes, et la remise (sur le total) devrait être répartie entre les lignes.
2. **Une seule fois, sur le total TTC de la facture** (après remise) : un seul arrondi.

### Le fichier PDF

1. **Stocker le PDF** à la création : un fichier par facture à conserver hors de la base.
2. **Le regénérer** à chaque téléchargement depuis les données figées de la facture.

## Décision

- Contenu : option 2. Entités `Invoice` et `InvoiceLine`, déclarées `readOnly` (Doctrine n'enregistre jamais de modification), sans setter ; seule `Invoice::issue()` les crée, et refuse une commande non payée ou un total différent de celui de la commande. Adresse de facturation = adresse de livraison de la commande (le site n'en demande pas d'autre). Vendeur et taux de TVA dans `config/services.yaml` (`app.invoice.*`).
- Numérotation : option 3 (`InvoiceSequenceRepository::nextNumber()`, SQL natif paramétré : DQL ne connaît pas `ON DUPLICATE KEY UPDATE`). Format `FA-<année>-<numéro sur 6 chiffres>`, numérotation repartant à 1 chaque année (année de la date d'émission).
- Création : `PaymentService::pay()` débite la commande, puis, **dans une même transaction**, verrouille la commande, la marque comme payée et appelle `InvoiceService::issue()`. Une commande payée a donc toujours sa facture, et un numéro n'est jamais pris sans facture enregistrée. La colonne `invoice.order_id` est unique : même en cas de bug, une commande ne peut pas avoir deux factures.
- Calcul : option 2, dans `VatCalculator` (entiers uniquement). **Règle d'arrondi** : HT = TTC × 100 / (100 + taux), arrondi au centime le plus proche, la moitié vers le haut ; TVA = TTC − HT. HT + TVA retombe toujours exactement sur le montant payé. Les lignes sont affichées en TTC (prix du site).
- PDF : option 2, avec dompdf (`InvoicePdfGenerator`, template `invoice/pdf.html.twig`, police DejaVu Sans pour l'euro et les accents, ressources distantes et PHP désactivés). Les données ne changeant jamais, le PDF regénéré est identique.
- Téléchargement : `/mes-commandes/{reference}/facture` (commande du client connecté, sinon 404) et `/admin/commandes/{reference}/facture` (`ROLE_ADMIN`).

## Hors périmètre

- **Avoirs** : une facture ne se supprime ni ne se modifie jamais ; une erreur ou un remboursement s'annule par un avoir (facture négative, numérotée dans sa propre séquence, qui référence la facture d'origine). Aujourd'hui, seule une commande non payée peut être annulée (ADR 0007), donc aucun avoir n'est encore nécessaire.
- Plusieurs taux de TVA (le taux est un pourcentage entier, unique pour tous les produits).
- Factures des commandes payées avant cette fonctionnalité : elles n'en ont pas (aucune n'existe dans les fixtures).

## Conséquences

- Le paiement fait deux écritures de plus (facture, compteur) dans sa transaction ; la ligne du compteur de l'année est un point de passage obligé : les paiements simultanés s'attendent le temps d'une transaction courte, ce qui convient au volume du projet.
- Les tests ne peuvent pas lancer deux paiements réellement simultanés (DAMA exécute chaque test dans une seule transaction) : ils vérifient les numéros consécutifs, l'absence de trou après un paiement refusé et l'unicité ; la sûreté face à la concurrence repose sur le verrou de MariaDB décrit ci-dessus.
- dompdf ne comprend que le CSS 2.1 : le template de la facture est mis en page avec des tableaux.
