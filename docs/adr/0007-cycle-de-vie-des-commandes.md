# ADR 0007 : Cycle de vie des commandes et annulation

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

L'administrateur doit pouvoir expédier, livrer et annuler les commandes. L'enum `OrderStatus` décrit les états (`pending`, `paid`, `shipped`, `delivered`, `cancelled`), mais seule la transition vers `paid` existait (`Order::markAsPaid()`). L'annulation doit aussi remettre en stock les produits commandés. Elle sera réutilisée plus tard, par exemple pour une annulation par le client ou une annulation automatique des commandes jamais payées.

(Le numéro 0006 est réservé à l'ADR de la branche `feature/locale-fr`, pas encore fusionnée.)

## Options étudiées

1. **Un `setStatus()` libre** appelé par le contrôleur : simple, mais rien n'empêche une transition absurde (livrer une commande annulée).
2. **Une méthode par transition dans l'entité**, sur le modèle de `markAsPaid()` : `canBeX()` dit si la transition est possible (utile aux templates), `markAsX()` / `cancel()` l'applique ou lève une `\DomainException`. Les règles restent au même endroit que les données.
3. **Le composant Symfony Workflow** : déclaration des états et transitions en configuration, avec des événements. Puissant, mais c'est une dépendance de plus et un mécanisme nettement plus complexe, pour quatre transitions.

## Décision

Option 2.

- Transitions de `Order` : `markAsShipped()` (depuis `paid`), `markAsDelivered()` (depuis `shipped`), `cancel()` (depuis `pending` uniquement). Chacune enregistre sa date (`shipped_at`, `delivered_at`, `cancelled_at`), comme `markAsPaid()` enregistre `paid_at`.
- **On n'annule qu'une commande en attente de paiement.** Annuler une commande payée demanderait de rembourser le client : le paiement est simulé et le remboursement est hors périmètre. On pourra ajouter plus tard un statut `refunded` et un appel au fournisseur de paiement.
- `Product::increaseStock()` est le pendant de `decreaseStock()`.
- `OrderService::cancel(Order)` :
  - ne dépend ni de la requête ni de l'utilisateur connecté : c'est à l'appelant (contrôleur d'administration, futur contrôleur client, future commande console) de vérifier les droits ;
  - vérifie la transition **avant** d'ouvrir la transaction : un refus ordinaire ne touche pas à la base ;
  - dans une seule transaction : verrouille la ligne de la commande (`OrderRepository::lockForUpdate()`) puis relit son statut, l'annule, puis verrouille chaque produit (`ProductRepository::lockForUpdate()`, le même verrou que `placeOrder()`) avant de remettre la quantité en stock ;
  - ignore une ligne dont le produit a été supprimé (`product_id` à `NULL`).
- `OrderService::ship()` et `deliver()` appliquent la transition et enregistrent. Elles ne modifient pas le stock, donc pas de verrou.
- Côté administration, chaque action est un formulaire POST protégé par un jeton CSRF (identifiant `order-status`). Une transition refusée est affichée en message flash d'erreur.

## Conséquences

- Le verrou sur la commande empêche deux annulations simultanées (par exemple un clic de l'administrateur et une tâche automatique) de remettre le stock deux fois.
- Si le statut change entre la vérification et le verrou, `cancel()` lève l'exception **dans** la transaction. Doctrine annule alors tout, mais ferme aussi l'`EntityManager` : l'appelant ne doit plus l'utiliser ensuite. C'est sans conséquence pour une requête HTTP. Pour une future tâche qui annule beaucoup de commandes d'affilée, il faudra réinitialiser l'`EntityManager` (`ManagerRegistry::resetManager()`) après un échec (*fait depuis par `app:orders:cancel-expired`, ADR 0024*).
- ~~Limite connue~~ (*corrigée le 2026-11-14, constat A-06 : `PaymentService::pay()` prend désormais ce verrou avant d'appeler le prestataire, voir ADR 0003*) : `PaymentService::pay()` ne verrouille pas la commande. Un paiement et une annulation exactement simultanés pourraient encore se croiser. Le risque est négligeable aujourd'hui (seul l'administrateur annule), mais il faudra ajouter ce verrou au paiement avant toute annulation automatique.
