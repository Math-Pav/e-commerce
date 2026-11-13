# ADR 0011 : Recherche, tri et pagination du catalogue

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

Le catalogue (`/produits`) affichait tous les produits actifs d'un coup, filtrables par catégorie (`?categorie=`). Il faut y ajouter une recherche (`?q=`), un tri (`?tri=`) et une pagination (`?page=`, 12 produits par page), qui se combinent.

Contraintes : tout fonctionne sans JavaScript ; chaque combinaison de filtres a sa propre adresse, que l'on peut partager ; aucune valeur de l'URL ne doit pouvoir casser la page (erreur 500) ni la requête SQL ; aucune dépendance Composer ajoutée.

## Options étudiées

### Lire les paramètres de l'URL

1. **Un argument du contrôleur par paramètre** (`#[MapQueryParameter]`) : quatre arguments, et la validation de chacun dispersée dans le contrôleur.
2. **`#[MapQueryString]` vers un objet** : la solution « officielle » de Symfony, mais elle demande le composant `symfony/serializer`, absent du projet. De plus, une valeur mal typée (`?page=abc`) y produit une erreur 404 au lieu d'être ignorée.
3. **Un objet dédié construit à partir de la requête** (`CatalogQuery::fromRequest()`) : aucune dépendance, et chaque règle (valeur par défaut, nettoyage) est écrite à un seul endroit et testée unitairement.

### Paginer

1. **`LIMIT`/`OFFSET` écrits à la main + une requête `COUNT` séparée** : faisable, mais on réécrit ce que Doctrine fournit déjà.
2. **`Doctrine\ORM\Tools\Pagination\Paginator`** : inclus dans Doctrine ORM, il exécute la requête de la page et une requête de comptage.

### Page au-delà de la dernière (`?page=99` alors qu'il n'y en a que 2)

1. **Erreur 404** : correcte au sens HTTP, mais un lien partagé devenu trop ancien (catalogue réduit) mène à une impasse.
2. **Redirection (302) vers la dernière page**, en gardant les autres filtres : le visiteur reste dans le catalogue et voit des produits.
3. **Afficher une page vide** : déroutant (« page 99 sur 2 »).

## Décision

- **`App\Model\CatalogQuery`** (option 3), objet immuable. Valeurs invalides remplacées par la valeur par défaut, jamais d'exception :
  - `q` : espaces regroupés et retirés aux extrémités, limité à 100 caractères (`SEARCH_MAX_LENGTH`) ; vide = pas de filtre ;
  - `tri` : valeur inconnue = tri par nom ;
  - `page` : absente, non numérique, inférieure à 1 ou trop grande pour un entier = 1 ;
  - un paramètre envoyé sous forme de tableau (`?q[]=...`) est ignoré.
  - `toRouteParameters()` omet les paramètres à leur valeur par défaut (pas de `page=1`, `tri=nom`, `q=` dans les liens générés) ; `withPage()`, `withCategory()` et `withoutSearch()` construisent les liens de pagination et de filtres sans perdre les autres paramètres. Changer de catégorie ou effacer la recherche ramène à la page 1.
- **Tri par liste blanche** : l'énumération `App\Enum\CatalogSort` (`nom`, `prix-croissant`, `prix-decroissant`, `nouveautes`). Le repository associe chaque cas à un champ écrit dans le code (`match`) : la valeur de l'URL n'arrive jamais dans le `ORDER BY`. L'identifiant `p.id` est ajouté en second critère : sans lui, deux produits de même prix pourraient changer d'ordre d'une requête à l'autre et apparaître sur deux pages, ou sur aucune.
- **Recherche** : `LIKE` sur `name` et `description`, avec un paramètre de requête (jamais de concaténation). Les jokers `%` et `_` saisis par le visiteur sont échappés avec `!` (`ESCAPE '!'`) pour être cherchés tels quels. On n'utilise pas `\` : dans une chaîne SQL MariaDB, `'\'` échappe le guillemet de fin et casse la requête.
- **Insensibilité à la casse et aux accents** : assurée par la collation des colonnes (`utf8mb4_uca1400_ai_ci` en développement et en test ; `ai` = accents ignorés, `ci` = casse ignorée) : « cafe » trouve « café ». Aucun `LOWER()` n'est nécessaire. Un test d'intégration le vérifie.
- **Paginator de Doctrine** (option 2), 12 produits par page (`ProductRepository::CATALOG_PAGE_SIZE`) :
  - `fetchJoinCollection: false` : seule une relation « vers un » (la catégorie) est jointe, chaque ligne SQL est donc un produit et `LIMIT` s'applique directement. `true` n'est utile que si l'on joint une collection (relation « vers plusieurs »), et ajoute une requête sur les identifiants ;
  - `setUseOutputWalkers(false)` : comptage simple `SELECT COUNT(DISTINCT p.id) ...` au lieu d'une sous-requête ;
  - le comptage n'est exécuté qu'une fois (`count()` met le résultat en cache dans le Paginator).
- **Calcul des pages** dans `App\Model\Pagination` : dernière page, page hors limites, et liste compacte (première, dernière, courante et ses voisines, « … » pour les pages sautées).
- **Page au-delà de la dernière** : redirection 302 vers la dernière page (option 2). Une redirection temporaire, car la dernière page change avec le catalogue.
- **Formulaire en GET** sans jeton CSRF : il ne modifie aucune donnée, il construit seulement une adresse. Un jeton apparaîtrait dans l'URL et rendrait les liens non partageables.

### Nombre de requêtes SQL

Une page du catalogue exécute 4 requêtes, quel que soit le nombre de produits affichés (vérifié par `ProductControllerTest::testCatalogueRunsAConstantNumberOfQueries`) : la catégorie choisie, le comptage, la page de produits avec leur catégorie (jointure, donc pas de N+1), la liste des catégories des filtres.

### Index

Index composites ajoutés, un par tri : `(is_active, name)`, `(is_active, price)`, `(is_active, created_at)`. Grâce à eux, MariaDB lit les produits actifs déjà dans l'ordre demandé et s'arrête aux 12 premiers, au lieu de lire et trier toute la table (« Using filesort » dans `EXPLAIN`). InnoDB ajoute la clé primaire à la fin de chaque index secondaire, ce qui couvre aussi le second critère `p.id`.

Mesure sur une table de test de 50 000 produits (copie temporaire de `product`, supprimée ensuite) :

| Requête | Sans index | Avec index |
|---|---|---|
| Page triée par nom | lecture complète + tri, ~30 ms | lecture de l'index, ~0,4 ms |
| Page triée par prix | lecture complète + tri, ~12 ms | ~0,2 ms |
| Recherche `LIKE '%abc%'` (comptage) | ~54 ms | ~53 ms (aucun gain) |

Index écartés :

- `is_active` seul : deux valeurs possibles, MariaDB ne l'utiliserait pas.
- Index combinant catégorie et tri (`(category_id, is_active, price)`, etc.) : trois index de plus pour un cas où la catégorie réduit déjà beaucoup le nombre de lignes (l'index de la clé étrangère `category_id` existe déjà). À reconsidérer si une catégorie dépasse quelques milliers de produits.

Avec la dizaine de produits actuelle, aucun index ne change rien de mesurable : ils préparent la croissance du catalogue, pour un coût faible (le catalogue est rarement modifié, et chaque index ralentit un peu les écritures).

### Limite de la recherche `LIKE '%…%'`

Un index B-tree (le type d'index par défaut) est trié comme un dictionnaire : il permet de trouver vite les noms qui **commencent** par un texte (`LIKE 'mug%'`), mais pas ceux qui le **contiennent** (`LIKE '%mug%'`), car le début du mot est inconnu. La recherche lit donc toutes les lignes des produits actifs (voir la mesure). C'est acceptable jusqu'à quelques dizaines de milliers de produits ; la longueur limitée à 100 caractères évite les recherches abusives.

**Piste future, non implémentée : index `FULLTEXT`** (`MATCH(name, description) AGAINST(:q)`), conçu pour la recherche de mots dans du texte : rapide sur de gros volumes et capable de classer les résultats par pertinence. Inconvénients pour l'instant : `MATCH ... AGAINST` n'existe pas en DQL (il faudrait une fonction DQL personnalisée ou une requête SQL native), ne trouve pas les morceaux de mots (« mu » ne trouve pas « mug »), ignore les mots trop courts (moins de 3 caractères par défaut) et les mots très fréquents. Au-delà, un moteur de recherche dédié (Meilisearch, Elasticsearch) serait l'étape suivante.

## Conséquences

- Les adresses sont partageables et courtes : `/produits?q=mug&tri=prix-croissant&page=2`.
- Une valeur invalide dans l'URL ne provoque jamais d'erreur 500 : elle est remplacée par la valeur par défaut.
- `ProductRepository::findActive()` est remplacée par `findCatalogPage()`.
- Ajouter un tri demande d'ajouter un cas à `CatalogSort` (valeur et libellé), sa correspondance dans `ProductRepository::applySort()`, et d'évaluer l'index correspondant.
- Un formulaire envoyé contient tous ses champs : après une recherche, l'URL contient aussi `tri=nom`. Seuls les liens générés par l'application omettent les valeurs par défaut. Rediriger vers l'adresse « propre » coûterait un aller-retour réseau de plus à chaque recherche, pénalisant sur une connexion lente : on ne le fait pas.
