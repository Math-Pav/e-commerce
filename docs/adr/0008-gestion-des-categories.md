# ADR 0008 : Gestion des catégories et générateur de slug partagé

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

L'administrateur doit créer, renommer et supprimer les catégories, avec les mêmes conventions que les produits : formulaire lié à un DTO (ADR 0005) et slug généré puis figé à la création (ADR 0004). La génération de slug unique était écrite dans `ProductService` ; les catégories en ont aussi besoin.

(Les numéros 0006 et 0007 sont pris par les branches `feature/locale-fr` et `feature/admin-orders`, pas encore fusionnées.)

## Options étudiées

### Réutiliser la génération de slug

1. **Copier la méthode dans `CategoryService`** : rapide, mais deux copies à maintenir.
2. **Service `SlugGenerator` qui reçoit une fonction « ce slug est-il pris ? »** (`callable(string): bool`) : le service ne connaît aucune entité ; chaque appelant fournit la méthode de son repository (`$productRepository->isSlugTaken(...)`). Facile à tester sans base.
3. **Interface `SlugRepositoryInterface`** implémentée par les deux repositories : plus formel, mais une interface de plus pour une seule méthode.

### Unicité du nom

1. **`UniqueEntity` sur l'entité** : la validation porte sur le DTO, pas sur l'entité ; la contrainte ne serait jamais vérifiée.
2. **`UniqueEntity` sur le DTO** avec `entityClass: Category::class` et `identifierFieldNames: ['id']` (possible depuis Symfony 7.1) : contrainte officielle, message d'erreur sur le formulaire, et la catégorie en cours de modification n'est pas comptée comme doublon.
3. **Contrainte de validation personnalisée** : plus de code pour le même résultat.

## Décision

- Génération de slug : option 2. `App\Service\SlugGenerator::generateUnique(texte, isTaken, longueur de colonne, slug de secours)`. Dix caractères de la colonne sont réservés au suffixe (`-2`, `-3`…). `ProductService` (colonne de 170) et `CategoryService` (colonne de 120, secours `categorie`) l'utilisent. Le comportement pour les produits est inchangé.
- Unicité du nom : option 2, **plus** un index unique sur `category.name` en base, qui reste le dernier rempart (deux créations simultanées).
- `CategoryData` (DTO) + `CategoryType` + `CategoryService` (`create`, `rename`, `delete`), sur le modèle des produits. Renommer ne change pas le slug.
- **Suppression** uniquement si la catégorie ne contient aucun produit, **désactivés compris** : la clé étrangère `product.category_id` est obligatoire, et supprimer des produits casserait l'historique. Le refus lève une `\DomainException` affichée en message flash.
- Liste d'administration : une seule requête `LEFT JOIN` + `COUNT` + `GROUP BY` (`CategoryRepository::findAllWithProductCount()`), et non une requête par catégorie.
- Catalogue public : les filtres n'affichent que les catégories qui ont au moins un produit actif (`findWithActiveProducts()`).

## Conséquences

- Une seule implémentation du slug, testée unitairement (`SlugGeneratorTest`).
- Les fixtures gardent leur propre `slugify()` : elles ne passent pas par les services. On pourra les aligner plus tard.
- Le slug dépend de la locale du slugger : `AsciiSlugger` remplace `&` par `and` en anglais, mais le retire en français. Une fois le site en `fr` (ADR 0006), « Agendas & plannings » donnera `agendas-plannings`. Les slugs déjà créés ne changent pas, puisqu'ils sont figés.
- Une catégorie dont les produits sont tous désactivés disparaît des filtres publics, mais son adresse `?categorie=` reste valide : elle affiche une liste vide.
