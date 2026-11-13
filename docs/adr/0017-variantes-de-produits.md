# ADR 0017 : Variantes de produits

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

Un même produit peut exister en plusieurs déclinaisons : un mug en plusieurs couleurs, un carnet en plusieurs formats. Chaque déclinaison a son propre stock et parfois son propre prix. Jusqu'ici, le stock et le prix étaient portés par `Product`, et tout le site en dépend : panier, commande (verrou, décrément, annulation), stock faible du tableau de bord, administration, codes promo (calculés sur le sous-total).

La base de développement contient de vraies données : la migration doit les conserver et pouvoir être annulée.

## Options étudiées

### Où placer le stock

1. **Stock sur `Product`, variantes facultatives** : un produit sans déclinaison garde son stock, un produit décliné utilise celui de ses variantes. Deux chemins dans tout le code (panier, commande, annulation, tableau de bord), avec des `if` partout.
2. **Stock toujours sur une variante** : tout produit a au moins une variante. Un produit sans déclinaison a une seule **variante par défaut**, sans libellé, invisible pour le client. Un seul chemin dans le code.
3. **Une déclinaison = un produit distinct** : aucun changement de modèle, mais une fiche, un slug et une photo par couleur, et aucun lien entre eux.

### Le prix

1. **Prix obligatoire sur chaque variante** : le prix du produit disparaît, il faut le saisir sur chaque variante.
2. **Prix du produit = prix de base, prix de la variante facultatif** : la variante qui n'a pas de prix prend celui du produit (`ProductVariant::getEffectivePrice()`).

### Le panier en session

Le panier stockait `[identifiant produit => quantité]` sous la clé `cart`. Il stocke désormais des identifiants de variantes.

1. **Garder la clé `cart`** : un ancien identifiant de produit serait lu comme un identifiant de variante. Les identifiants des variantes créées par la migration peuvent coïncider avec ceux d'autres produits : le client verrait apparaître un autre article.
2. **Convertir l'ancien panier** (produit → variante par défaut) : faisable, mais du code de conversion à garder pour un cas transitoire.
3. **Nouvelle clé `cart_variants`** : l'ancienne clé `cart` est supprimée à la première lecture du panier, avec un message invitant à rajouter les articles.

## Décision

- **Stock** : option 2. Nouvelle entité `ProductVariant` (table `product_variant`) : produit, libellé (`label`, facultatif), référence unique (`sku`), prix facultatif en centimes, stock, active ou non. La colonne `product.stock` est supprimée.
- **Variante par défaut** : `label` vide (`NULL`). Un libellé devient obligatoire dès qu'un produit a plusieurs variantes (vérifié par le formulaire d'administration) : le client ne voit jamais un choix vide.
- **Prix** : option 2. `product.price` reste obligatoire (prix de base) ; `product_variant.price` est facultatif.
- **Disponibilité** : un produit est « en stock » si au moins une de ses variantes actives l'est. Le catalogue affiche « à partir de … € » quand les prix des variantes actives diffèrent, et « Rupture de stock » seulement quand toutes sont épuisées. Le tri par prix utilise le plus petit prix des variantes actives (sous-requête), c'est-à-dire le prix affiché : l'index `idx_product_active_price` ne sert plus et est supprimé.
- **Fiche produit** : le client choisit la variante par des boutons radio dans le formulaire d'ajout au panier (sans JavaScript). Chaque choix affiche son prix et sa disponibilité ; une variante épuisée est désactivée.
- **Panier** : option 3 (clé `cart_variants`). À chaque affichage du panier, les variantes devenues indisponibles (supprimées, désactivées, ou produit désactivé) sont retirées avec un message. Le compteur de l'en-tête ne retire rien et n'affiche aucun message (même règle que les codes promo, ADR 0016).
- **Commande** : `OrderService` verrouille, décrémente et remet en stock la **variante**. `order_item` reçoit `variant_id` (`SET NULL`) et `variant_label` (copie du libellé, comme le nom et le prix). `order_item.product_id` est conservé : il sert aux meilleures ventes et à la photo.
- **Codes promo** : rien ne change, ils s'appliquent au sous-total du panier et de la commande, calculé avec le prix de chaque variante.
- **Administration** : une page « Variantes » par produit (ajout, modification, activation, stock). La création d'un produit crée sa variante par défaut avec le stock saisi et une référence générée (`PRD-` + 8 caractères aléatoires, modifiable ensuite).
- Tout futur avis ou favori se rattachera au **produit**, pas à la variante : on donne son avis sur un mug, pas sur sa couleur.

## Migration des données

`up()` :

1. crée `product_variant` ;
2. crée une variante par défaut par produit, en reprenant son stock : `sku = PRD-<id sur 5 chiffres>`, prix vide (celui du produit), active ;
3. ajoute `order_item.variant_id` et `variant_label`, et rattache les lignes existantes à la variante par défaut de leur produit (une commande en attente reste annulable : son stock revient sur la bonne variante) ;
4. supprime `product.stock` et l'index `idx_product_active_price`.

`down()` remet `product.stock` à la **somme** des stocks des variantes du produit (aucun article n'est perdu), puis supprime les colonnes et la table. Les libellés, références et prix propres aux variantes sont perdus : un avertissement l'indique si des produits ont plusieurs variantes.

Vérifications : `up()` → `down()` → `up()` sur la base de test, et un test automatisé (`tests/Migrations/`) qui rejoue la migration sur une base temporaire et vérifie que les stocks se retrouvent sur les variantes par défaut. La base de développement est sauvegardée (`mysqldump`) avant d'y appliquer la migration.

## Code concerné

- Entités : `Product` (stock retiré, disponibilité calculée sur les variantes), `ProductVariant` (nouvelle : stock, prix effectif), `OrderItem` (variante + copie du libellé).
- Repositories : `ProductVariantRepository` (nouveau : variantes disponibles, verrou, stock faible), `ProductRepository` (tri par prix, chargement des variantes du catalogue).
- Services : `CartService`, `OrderService`, `ProductService`, `ProductVariantService` (nouveau) ; `InsufficientStockException` porte la variante.
- Modèles : `CartLine`, `ProductData`, `ProductVariantData` (nouveau).
- Contrôleurs et vues : panier, commande, fiche produit, catalogue, tableau de bord, administration des produits et des variantes, e-mails et détails de commande (libellé de la variante).
- Fixtures et tests.

## Conséquences

- Le stock d'un produit se gère désormais dans sa page « Variantes » ; le formulaire de modification du produit ne contient plus de stock.
- Les paniers en session au moment de la mise à jour sont vidés une fois, avec un message.
- Le tri par prix ne profite plus d'un index : acceptable pour la taille du catalogue (ADR 0011).
