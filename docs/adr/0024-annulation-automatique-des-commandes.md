# ADR 0024 : Annulation automatique des commandes non payées

- **Date** : 2026-11-15
- **Statut** : accepté

## Contexte

Le stock est réservé dès la création de la commande (ADR 0003), et l'utilisation du code promo est comptée au même moment (ADR 0016). Une commande que le client ne paie jamais garde donc son stock et son code **indéfiniment** (constat A-02 de `docs/audit.md`) : un produit peut apparaître épuisé alors que personne ne l'a acheté, et un code limité peut être épuisé par des commandes abandonnées.

Trois questions : après quel délai annuler, comment déclencher l'annulation, et que dire au client.

## Options étudiées

### Délai

- **1 heure** (retenu) : le paiement est immédiat sur ce site (pas de virement ni de chèque) ; une heure laisse largement le temps de revenir après une coupure ou un refus, sans bloquer le stock une journée.
- 24 heures : adapté à un paiement différé (virement), inutile ici, et le stock reste bloqué longtemps.

Le délai est un **paramètre de configuration** (`app.order.payment_delay_minutes: 60` dans `config/services.yaml`), pas une constante : on peut le changer sans toucher au code.

### Déclenchement

1. **Commande console lancée par le cron du serveur** (retenu) : `app:orders:cancel-expired`, toutes les 5 minutes. Aucune dépendance ; le cron est déjà utilisé pour les sauvegardes (`docs/deploiement.md`).
2. **Symfony Scheduler** : planification décrite en PHP, mais c'est une nouvelle dépendance (`symfony/scheduler`, plus Messenger et un processus `messenger:consume` à surveiller en permanence). Disproportionné pour une seule tâche.
3. **Annulation « paresseuse »** à l'affichage (au chargement d'une page, on annule ce qui a expiré) : pas de cron, mais une simple visite écrirait en base, envoie des e-mails pendant la requête d'un autre client, et rien ne se passe si personne ne visite le site.

### Logique d'annulation

L'annulation automatique **réutilise exactement** celle du client et de l'administrateur : `OrderService::cancelExpired()` et `cancel()` appellent la même méthode privée `cancelPendingOrder()` (une transaction, verrou sur la commande, puis sur chaque variante pour rendre le stock, puis sur le code promo pour rendre son utilisation). Seul l'e-mail diffère.

## Décision

- `OrderRepository::findExpiredPendingIds(\DateTimeImmutable)` renvoie les identifiants des commandes `pending` créées avant « maintenant − délai ».
- `CancelExpiredOrdersCommand` (`src/Command/`) :
  - traite les commandes **une par une**, chacune dans sa propre transaction ; elle recharge chaque commande et ignore celle qui a été payée ou annulée entre-temps ;
  - une erreur sur une commande est journalisée et affichée, l'`EntityManager` (fermé par l'échec de la transaction, voir ADR 0007) est réinitialisé avec `ManagerRegistry::resetManager()`, puis la commande suivante est traitée. Le code de sortie est alors `1`, pour que le cron (ou une supervision) le signale ;
  - seules les `\Exception` sont interceptées : une erreur de programmation (`TypeError`…) arrête la commande au lieu d'être répétée sur chaque commande ;
  - **idempotente** : la relancer n'annule rien de plus (seules les commandes encore en attente sont sélectionnées, et le verrou relit le statut) ;
  - option `--dry-run` : liste ce qui serait annulé sans rien modifier ;
  - affiche un résumé (annulées, ignorées, échecs).
- Le client reçoit l'e-mail d'annulation existant (`emails/order_cancellation.*.twig`, ADR 0012), avec la variable `expired` qui remplace la phrase finale par « annulée automatiquement, faute de paiement dans le délai prévu ». `CustomerMailer::sendExpiredOrderCancellation()`.
- Paiement et annulation automatique ne peuvent pas se croiser : depuis la correction de A-06 (ADR 0003), `PaymentService::pay()` prend le même verrou sur la commande **avant** d'appeler le prestataire. Celui des deux qui arrive second relit le statut et s'arrête.

## Conséquences

- En production, une ligne de cron (voir `docs/deploiement.md`). En développement, on lance la commande à la main : `docker compose exec php bin/console app:orders:cancel-expired --dry-run`, puis sans l'option.
- Une commande peut rester payable jusqu'à 5 minutes après l'expiration du délai (intervalle du cron). C'est sans gravité : le client qui paie à 1 h 03 obtient une commande normale. Si un jour il fallait une limite stricte, `Order::canBePaid()` pourrait aussi comparer la date de création au délai.
- Le texte de l'e-mail ne cite pas la durée (« délai prévu ») : il reste juste si le paramètre change. La page de commande n'annonce pas encore ce délai au client ; ce serait une amélioration utile.
- Pas d'index sur `(status, created_at)` : au volume d'un projet d'apprentissage, le parcours de la table est négligeable. À ajouter si les commandes se comptent en centaines de milliers.
- Les commandes sont annulées au fil de l'eau, sans limite par exécution : après une longue panne du cron, la première exécution peut en traiter beaucoup, ce qui reste correct grâce au traitement une par une.
