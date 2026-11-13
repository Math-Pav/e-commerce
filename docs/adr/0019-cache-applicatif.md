# ADR 0019 : Cache applicatif et invalidation par étiquettes

- **Date** : 2026-11-14
- **Statut** : accepté

## Contexte

Le tableau de bord (`/admin`) relance à chaque affichage ses requêtes d'agrégation (`SUM`, `COUNT … GROUP BY`, ADR 0009), et le catalogue relit à chaque page la liste des catégories de ses filtres. Ces données changent rarement par rapport au nombre d'affichages.

Règle fixée : **une donnée en cache ne doit jamais rester fausse après une modification**. En cas de doute, on invalide (ou on ne met pas en cache).

## Décision

### Pool dédié avec étiquettes

`config/packages/cache.yaml` déclare le pool `computed.cache` (stockage fichiers de `cache.app`, `tags: true`, durée de vie par défaut 1 h). Il s'injecte par `TagAwareCacheInterface $computedCache`. Une **étiquette** (tag) est un nom attaché à plusieurs entrées : `invalidateTags(['dashboard'])` les rend toutes périmées d'un coup, sans connaître leurs clés.

Le pool est séparé de `cache.app` pour pouvoir le vider seul (`bin/console cache:pool:clear computed.cache`).

### Ce qui est mis en cache

| Donnée | Clé | Étiquette | Expiration |
|---|---|---|---|
| Chiffre d'affaires total, nombre de commandes, CA des 30 derniers jours | `dashboard.sales_summary.<jour>` | `dashboard` | minuit (Paris) |
| CA jour par jour (14 jours) | `dashboard.daily_revenue_14.<jour>` | `dashboard` | minuit (Paris) |
| Commandes par statut | `dashboard.orders_by_status` | `dashboard` | 1 h (filet) |
| Meilleures ventes | `dashboard.best_sellers_5` | `dashboard` | 1 h (filet) |
| Catégories des filtres du catalogue | `catalog.filter_categories` | `catalog_categories` | 1 h (filet) |

- **Périodes glissantes** : « 30 derniers jours » et « 14 derniers jours » dépendent de la date du jour. La date (heure de Paris) fait partie de la clé et l'entrée expire à minuit : après minuit, une nouvelle clé est calculée, jamais celle d'hier.
- **On met en cache des tableaux et des objets simples**, jamais des entités : une entité relue du cache n'est pas suivie par Doctrine (modifications perdues, relations non chargées). Les catégories des filtres sont donc stockées sous forme `{id, name, slug}`.

### Ce qui n'est PAS mis en cache

- **Variantes à réapprovisionner** (stock faible) : la liste baisse à chaque commande, remonte à chaque annulation, change dans l'administration des variantes et des produits (activation). Elle renvoie des entités, et sa requête est simple et indexée. La mettre en cache demanderait d'invalider sur `ProductVariant` à presque chaque écriture, pour un gain nul.
- **Codes promo** : le tableau de bord n'en affiche pas ; rien à faire.
- **Catégorie courante et produits du catalogue** : dépendent des paramètres de la requête (recherche, tri, page) et du stock ; une clé par combinaison ferait beaucoup d'entrées peu réutilisées.

### Où se déclenche l'invalidation : un écouteur Doctrine

Options étudiées :

1. **Dans les services** (`PaymentService::pay()`, `OrderService::cancel()`, `CategoryService`, `ProductService`…) : explicite et lisible, et l'appel peut être placé après le `COMMIT`. Mais il faut l'ajouter dans **chaque** méthode qui écrit, y compris celles qu'on n'associe pas au tableau de bord : `OrderService::placeOrder()` (une commande en attente change « commandes par statut »), `ProductService::update()` (le nom d'un produit apparaît dans les meilleures ventes), les fixtures… Un oubli, aujourd'hui ou dans une future fonctionnalité, laisse une donnée fausse sans qu'aucun test ne le voie.
2. **Écouteur Doctrine** (`src/EventListener/CacheInvalidationListener.php`) sur `onFlush` / `postFlush` : à chaque `flush()`, il regarde les entités insérées, modifiées ou supprimées et invalide les étiquettes associées à leur classe :
   - `Order`, `OrderItem` → `dashboard` (paiement, expédition, livraison, annulation, mais aussi création de commande) ;
   - `Product` → `dashboard` (nom dans les meilleures ventes) et `catalog_categories` (activation, changement de catégorie) ;
   - `Category` → `catalog_categories` (création, renommage, suppression).

**Choix : option 2.** Toute écriture passant par l'ORM est vue, quel que soit le code qui la fait : on ne peut pas oublier un chemin. L'écouteur invalide parfois « pour rien » (une commande créée sans changer le CA), ce qui est sans danger : la règle est d'invalider dans le doute.

`ProductVariant` n'invalide rien : les filtres listent les catégories ayant un **produit actif** (`CategoryRepository::findWithActiveProducts()`), sans regarder le stock ni l'activation des variantes, et le stock faible n'est pas en cache. Si un jour les filtres ne montrent que les catégories ayant une variante disponible, il faudra ajouter `ProductVariant` à la table de correspondance de l'écouteur.

#### Limites de l'écouteur, et parades

- **Transactions** : `postFlush` a lieu avant le `COMMIT` quand le `flush()` est dans `wrapInTransaction()` (commande, paiement, annulation). Une autre requête qui recalculerait le cache entre les deux lirait les anciennes données et les remettrait en cache. Parade : l'écouteur **invalide une seconde fois** les mêmes étiquettes sur `kernel.terminate` / `console.terminate`, une fois la transaction validée et la réponse envoyée.
- **Requêtes DQL ou SQL directes** (`DELETE FROM …`, `UPDATE …`) : elles ne passent pas par l'unité de travail, l'écouteur ne les voit pas. Le code de l'application n'en fait pas sur ces entités ; si on en ajoute, il faut invalider à la main. Même chose pour une modification faite directement dans Adminer : `bin/console cache:pool:clear computed.cache`.
- Le filet de sécurité de 1 h borne la durée d'une éventuelle erreur dans ces cas.

### Tests

- `DashboardServiceTest` construit le service avec un cache neutre (`NullAdapter`) : il teste les calculs.
- Les tests fonctionnels vérifient le cache réel : le second affichage du tableau de bord ne lance aucune requête d'agrégation ; après le paiement d'une commande, le CA affiché est à jour ; une catégorie créée avec un produit actif apparaît dans les filtres, et disparaît quand son dernier produit est désactivé.
- DAMA annule les écritures en base à la fin de chaque test, mais pas le cache : l'extension PHPUnit `tests/ClearCachePoolsExtension.php` vide `var/share/test/pools/app` avant chaque test.

## Pourquoi pas de cache HTTP des pages du catalogue

Le cache HTTP (en-têtes `Cache-Control: public, max-age`, reverse proxy comme le `HttpCache` de Symfony ou Varnish) stocke **la page entière** et la sert à tous les visiteurs sans exécuter PHP. C'est le cache le plus efficace, mais nos pages ne sont pas les mêmes pour tout le monde : l'en-tête de `base.html.twig` affiche le **nombre d'articles du panier** (session) et l'**utilisateur connecté** (initiales, prénom, lien d'administration). Une page mise en cache pour un visiteur serait servie à un autre, avec son panier et son nom : une fuite de données personnelles. Symfony l'empêche d'ailleurs par défaut : dès que la session est utilisée, la réponse est marquée `private`.

Pour pouvoir le faire, il faudrait séparer la partie commune de la partie personnelle :

- servir le catalogue **sans session** (ni lecture du panier ni de l'utilisateur pendant le rendu) avec `Cache-Control: public` et une durée, ou une invalidation (purge) du proxy à chaque modification de produit ;
- charger les morceaux personnels à part : **ESI** (`render_esi()`, des fragments cachés séparément et assemblés par le proxy) ou une petite requête JavaScript (`fetch`) qui remplit le compteur du panier et le menu du compte après l'affichage ;
- ajouter un reverse proxy (le `HttpCache` de Symfony suffit en local).

Ce travail dépasse le besoin actuel : le cache applicatif supprime déjà les requêtes coûteuses.

## Mesures (profiler, données de développement, admin connecté)

| Page | Avant : requêtes / temps | Après, cache rempli : requêtes / temps |
|---|---|---|
| `/admin` | 7 / ~25-30 ms | 2 / ~17 ms |
| `/produits` | 5 / ~20 ms | 4 / ~20 ms |

Les deux requêtes restantes de `/admin` sont l'utilisateur connecté et les variantes à réapprovisionner. Sur `/produits`, le gain est d'une requête : la liste des catégories est petite ; il grandira avec le volume. Les temps sont ceux d'un poste local, à titre indicatif.

## Conséquences

- Toute nouvelle donnée mise en cache doit avoir une étiquette, et sa ou ses classes d'entités doivent figurer dans `CacheInvalidationListener::TAGS_BY_ENTITY`.
- Toute écriture en DQL/SQL direct sur ces entités doit invalider le cache elle-même.
- Le profiler Symfony (`symfony/profiler-pack`, dépendance de développement) est installé pour mesurer requêtes et temps : barre en bas des pages en développement, détails sur `/_profiler`.
