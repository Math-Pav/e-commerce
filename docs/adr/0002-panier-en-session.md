# ADR 0002 : Panier stocké en session

- **Date** : 2026-11-10
- **Statut** : accepté

## Contexte

Le panier doit fonctionner pour les visiteurs non connectés. Le MVP doit rester simple.

## Options étudiées

1. **Session** : simple, sans table supplémentaire, mais perdu à l'expiration de la session.
2. **Base de données** (entités Cart et CartItem) : persistant et multi-appareil, mais impose la gestion des paniers abandonnés et la fusion à la connexion.

## Décision

Panier en session, sous la forme `[identifiant produit => quantité]`. Les prix et le stock sont toujours relus en base.

## Conséquences

- Le panier est perdu à l'expiration de la session.
- Le stock peut changer entre l'ajout au panier et la commande : il sera revérifié lors de la validation de la commande.
- Le stockage est isolé dans `CartService` : un passage en base ne modifiera que ce service.