# ADR 0016 : Codes promo

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

Le client doit pouvoir appliquer un code promo à son panier (un seul par commande) : remise en pourcentage ou d'un montant fixe, avec dates de validité, nombre maximal d'utilisations et montant minimal du panier facultatifs. Le panier est en session (ADR 0002) et une commande fige déjà les prix (copie dans `order_item`).

## Options étudiées

### Ce que la session garde

1. **L'objet `PromoCode`** : ses données (actif, compteur…) seraient périmées dès qu'elles changent en base.
2. **Le texte du code seulement**, relu et revérifié en base à chaque lecture du panier (`CartService::getCart()`) : un code désactivé, expiré ou dont le minimum n'est plus atteint disparaît avec un message.

### Compter les utilisations sans dépasser le maximum

1. **Vérifier puis incrémenter sans verrou** : deux commandes simultanées peuvent lire « 9 / 10 » et passer toutes les deux.
2. **Verrou pessimiste sur la ligne du code** (`SELECT … FOR UPDATE`, même mécanisme que le stock), dans la transaction de la commande : la seconde commande attend, relit « 10 / 10 » et est refusée.
3. **Requête `UPDATE … WHERE usage_count < max_usages`** : atomique, mais une requête écrite à la main en plus de l'entité.

### Lien entre la commande et le code

1. **Copie du texte du code seulement** : suffit à l'affichage, mais ne permet pas de retrouver le code (s'il est renommé) pour rendre l'utilisation à l'annulation.
2. **Copie du texte + relation `promo_code_id`** (`SET NULL`) : la copie fige ce qui a été facturé, la relation sert au compteur.

## Décision

- Session : option 2 (clé `cart_promo_code`). La vérification est faite par `PromoCode::assertApplicable()`, qui lève une `InvalidPromoCodeException` avec un message pour le client. Le compteur du panier (en-tête) ne lit pas le code : il ne retire rien et n'affiche aucun message.
- Compteur : option 2. `OrderService::placeOrder()` verrouille le code, le revérifie, incrémente `usage_count` et recalcule la remise sur le sous-total de la commande, dans la même transaction que le stock. `OrderService::cancel()` rend l'utilisation sous le même verrou.
- Commande : option 2. `orders` reçoit `subtotal_amount`, `discount_amount`, `discount_code` (copie) et `promo_code_id`. `total_amount` reste le montant facturé (sous-total − remise) : le paiement et le chiffre d'affaires du tableau de bord n'ont pas changé.
- Calcul : `DiscountCalculator`, sans dépendance. Pourcentage arrondi au centime inférieur (`intdiv`), montant fixe plafonné au sous-total.
- Administration : DTO `PromoCodeData` avec deux champs de valeur (pourcentage entier, ou montant saisi en euros et enregistré en centimes, comme le prix d'un produit) ; seul celui du type choisi est obligatoire et enregistré. Pas de suppression : un code se désactive.

## Conséquences

- Le code est enregistré en majuscules (`PromoCode::normalize()`) : la saisie du client ne dépend pas de la casse.
- Un code qui devient inapplicable entre l'affichage du panier et la validation de la commande fait échouer la commande : le client est renvoyé au panier, le code est retiré avec un message.
- Une commande annulée rend son utilisation ; une commande payée la garde.
