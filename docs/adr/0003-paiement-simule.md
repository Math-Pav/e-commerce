# ADR 0003 : Paiement simulé derrière une interface

- **Date** : 2026-11-12
- **Statut** : accepté

## Contexte

Le MVP doit permettre de payer une commande, sans prestataire réel ni données bancaires.

## Décision

- Le paiement passe par `PaymentProviderInterface`. L'implémentation actuelle, `FakePaymentProvider`, accepte tout paiement jusqu'à 100 € et refuse au-delà, pour pouvoir tester un refus.
- Le stock est réservé à la création de la commande, avant le paiement. Un paiement refusé laisse la commande « en attente » : le client peut réessayer.
- Une commande ne peut être payée que depuis l'état « en attente » (`Order::markAsPaid()`), ce qui empêche tout double débit.

## Conséquences

- Brancher un vrai prestataire (Stripe en mode test, par exemple) ne demandera qu'une nouvelle classe implémentant l'interface, et une ligne de configuration.
- Avec un vrai prestataire, le site ne devra jamais manipuler de numéro de carte : la saisie se fera sur une page ou un composant du prestataire.
- Les commandes jamais payées bloquent du stock : il faudra, à terme, les annuler automatiquement après un délai.