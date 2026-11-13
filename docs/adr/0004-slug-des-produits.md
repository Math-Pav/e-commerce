# ADR 0004 : Slug des produits généré automatiquement et figé à la création

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

L'adresse d'une fiche produit utilise un slug (`/produits/mug-noir-mat`). Depuis l'administration, le slug ne doit pas être saisi à la main : il est généré à partir du nom et doit rester unique (colonne `product.slug` unique). Il faut décider de ce qu'il devient quand l'administrateur modifie le nom du produit.

## Options étudiées

1. **Slug figé à la création** : le slug est généré une seule fois ; renommer le produit ne change pas son adresse.
   - Les liens déjà partagés, les favoris et le référencement restent valides.
   - Le slug peut ne plus correspondre exactement au nom (ex. « Mug noir mat XL » garde `mug-noir-mat`).
2. **Slug régénéré à chaque changement de nom** : l'adresse suit toujours le nom.
   - Chaque renommage casse les anciens liens (erreur 404), sauf à gérer des redirections.
3. **Slug régénéré avec historique et redirection 301** : une table des anciens slugs redirige vers l'adresse actuelle.
   - Le meilleur des deux, mais une entité, une migration et une logique de redirection en plus : trop lourd pour le MVP.
4. **Slug modifiable à la main par l'administrateur** (pré-rempli automatiquement).
   - Souple, mais demande une validation (format, unicité) et laisse l'administrateur casser les liens sans le savoir.

## Décision

Option 1 : le slug est généré à la création par `ProductService` puis **n'est plus jamais modifié**.

Génération :

- `SluggerInterface` (composant officiel Symfony String, `AsciiSlugger`) : « Mug à café « Été » » → `mug-a-cafe-ete` ;
- en cas de doublon, ajout d'un suffixe numérique : `mug-noir-mat-2`, `mug-noir-mat-3`… (vérifié par `ProductRepository::isSlugTaken()`) ;
- slug de base limité à 160 caractères pour laisser la place au suffixe dans la colonne de 170 ;
- nom sans aucun caractère utilisable (ex. « !!! ») : slug de secours `produit`.

## Conséquences

- Les adresses des fiches produit sont stables. Le formulaire d'édition affiche l'adresse pour que l'administrateur sache qu'elle ne change pas.
- Un produit fortement renommé garde un slug qui ne lui ressemble plus. Si le besoin apparaît, on pourra ajouter l'option 3 sans casser l'existant.
- La vérification d'unicité se fait avant l'insertion : deux créations simultanées du même nom pourraient obtenir le même slug. La contrainte unique en base empêche alors le doublon (la seconde création échoue). Le risque est négligeable avec un seul administrateur.
- `symfony/string` est installé comme dépendance d'autres composants Symfony mais n'est pas déclaré dans `composer.json`. Il serait plus sûr de l'ajouter explicitement (`composer require symfony/string`), ce qui demande l'accord du développeur.

## Produit désactivé

Désactiver un produit ne le supprime pas (`is_active = false`) : il reste dans l'administration et dans l'historique des commandes, mais disparaît du catalogue et de sa fiche (404). Côté panier, `CartService` ne relit que les produits actifs : un produit désactivé disparaît du panier, du compteur de l'en-tête et ne peut donc pas être commandé.
