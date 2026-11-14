# ADR 0003 : Paiement simulé derrière une interface

- **Date** : 2026-11-12
- **Statut** : accepté

## Contexte

Le MVP doit permettre de payer une commande, sans prestataire réel ni données bancaires.

## Décision

- Le paiement passe par `PaymentProviderInterface`. L'implémentation actuelle, `FakePaymentProvider`, accepte tout paiement **sauf une commande dont le total vaut exactement 9 999,99 €** (`FakePaymentProvider::DECLINED_AMOUNT`), qui est refusée pour pouvoir tester un refus.
- *Mis à jour le 2026-11-15 (constat A-02)* : à l'origine, tout paiement de plus de 100 € était refusé. Ce plafond bloquait des commandes ordinaires (4 stylos plume à 139,60 €), qui restaient en attente avec leur stock réservé. Il est supprimé. Pour garder un refus **déterministe**, on reprend l'idée des numéros de carte de test des vrais prestataires (Stripe : `4000 0000 0000 0002` est toujours refusée). La page de paiement ne demande pas de carte, donc le déclencheur est un **montant total précis** : 9 999,99 €. Il est très au-dessus des paniers de la boutique (produits de 1,50 € à quelques dizaines d'euros) et ne peut être atteint que volontairement ; 9 999,98 € ou 10 000,00 € sont acceptés.
  - Alternatives écartées : un nom de livraison « magique » (le refus dépendrait d'une donnée personnelle saisie par un vrai client) ; un champ « simuler un refus » sur la page de paiement (visible par les clients, ou à masquer selon l'environnement) ; un refus aléatoire (non reproductible, impossible à tester).
  - Pour reproduire un refus à la main : dans l'administration, créer un produit (ou une variante) à 9 999,99 €, en commander un exemplaire sans code promo, puis cliquer « Payer ». Les tests imposent ce total directement en base.
- Le stock est réservé à la création de la commande, avant le paiement. Un paiement refusé laisse la commande « en attente » : le client peut réessayer.
- Une commande ne peut être payée que depuis l'état « en attente » (`Order::markAsPaid()`).
- *Mis à jour le 2026-11-14 (constat A-06 de `docs/audit.md`)* : `PaymentService::pay()` ouvre une transaction, **verrouille la commande** (`OrderRepository::lockForUpdate()`, `SELECT … FOR UPDATE`, le même verrou que l'annulation), relit son statut, et n'appelle le prestataire **que si elle est toujours en attente**. Le débit, le passage à « payée » et la facture se font sous ce verrou. Avant, le statut était vérifié sans verrou puis le prestataire appelé, et le verrou n'était pris qu'après le débit : deux clics simultanés débitaient deux fois, et une annulation glissée entre les deux laissait un client débité d'une commande annulée.
- Les refus ordinaires (commande plus payable, paiement refusé) sont **renvoyés** par la fonction de la transaction et transformés en exception après elle : une exception levée dans `wrapInTransaction()` fermerait l'`EntityManager`.

## Conséquences

- Brancher un vrai prestataire (Stripe en mode test, par exemple) ne demandera qu'une nouvelle classe implémentant l'interface, et une ligne de configuration.
- Avec un vrai prestataire, le site ne devra jamais manipuler de numéro de carte : la saisie se fera sur une page ou un composant du prestataire.
- Le verrou est gardé pendant l'appel au prestataire. Avec un vrai prestataire (appel réseau d'une ou deux secondes), une annulation simultanée de la même commande attend ce délai : c'est voulu, et cela ne bloque que cette commande. Il faudra aussi fixer un délai d'attente court à l'appel HTTP du prestataire et lui transmettre une **clé d'idempotence** (la référence de la commande), pour qu'une nouvelle tentative après une coupure ne débite pas deux fois.
- Limite connue : si le prestataire accepte le débit mais que la transaction échoue ensuite (base indisponible pendant l'émission de la facture), le client est débité d'une commande restée en attente. Avec un vrai prestataire, il faudra rembourser automatiquement ce cas (ou confirmer le paiement par webhook).
- Les commandes jamais payées bloquent du stock : il faudra, à terme, les annuler automatiquement après un délai.