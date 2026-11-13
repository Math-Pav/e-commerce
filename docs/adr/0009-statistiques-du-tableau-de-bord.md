# ADR 0009 : Statistiques du tableau de bord calculées en base

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

L'administration a besoin d'une vue d'ensemble (`/admin`, route `admin_dashboard`) : chiffre d'affaires total et des 30 derniers jours, nombre de commandes par statut, panier moyen, top 5 des produits vendus, produits à réapprovisionner et chiffre d'affaires des 14 derniers jours, jour par jour, en heure de Paris.

Le nombre de commandes ne fait que grandir : charger toutes les commandes pour les additionner en PHP deviendrait de plus en plus lent et gourmand en mémoire.

(Les numéros 0006, 0007 et 0008 sont pris par les branches `feature/locale-fr`, `feature/admin-orders` et `feature/admin-categories`, pas encore fusionnées.)

## Options étudiées

1. **Charger les commandes et calculer en PHP** : simple à écrire, mais coûteux dès qu'il y a beaucoup de commandes.
2. **Requêtes d'agrégation (SUM, COUNT, GROUP BY) dans les repositories** : la base renvoie quelques lignes déjà calculées.
3. **Tables de statistiques pré-calculées** (mises à jour à chaque paiement ou par une tâche planifiée) : très rapide à lire, mais il faut maintenir ces tables à jour. Inutile au volume d'un MVP.

Pour le regroupement par jour :

- a. **DQL** : DQL ne connaît ni `DATE()` ni `CONVERT_TZ()`. Il faudrait déclarer des fonctions DQL personnalisées (ou installer `beberlei/doctrineextensions`, une dépendance de plus).
- b. **Regrouper par jour UTC** (par exemple sur `SUBSTRING(o.paidAt, 1, 10)`) : faux pour la France, une commande payée à 00:30 à Paris serait comptée la veille.
- c. **Requête SQL native paramétrée** dans le repository, avec `DATE(CONVERT_TZ(paid_at, <fuseau de stockage>, 'Europe/Paris'))`.

## Décision

Option 2, et option c pour le regroupement par jour.

- Une commande compte dans le chiffre d'affaires si elle est payée, expédiée ou livrée : `OrderStatus::revenueStatuses()`. Les montants sont ceux de `orders.total_amount` (en centimes, figés à la commande).
- Les périodes portent sur la **date de paiement** (`paid_at`) : c'est le moment où la vente est acquise.
- `OrderRepository::sumRevenue()` (DQL, `SUM` et `COUNT`), `countByStatus()` (DQL, `GROUP BY status`), `sumRevenueByDay()` (SQL natif), `OrderItemRepository::findBestSellingProducts()` (DQL, `SUM(quantity)`, `GROUP BY` produit), `ProductRepository::findOutOfStockOrLowStock()` (`stock <= Product::LOW_STOCK_THRESHOLD`, la même règle que `isInStock()` et `isLowStock()`).
- `DashboardService` fixe les périodes et met en forme : jours sans vente à 0, statuts sans commande à 0. Il reçoit « maintenant » en paramètre, ce qui rend les tests indépendants de l'heure à laquelle ils tournent.
- « 30 derniers jours » et « 14 derniers jours » sont des **jours entiers** en heure de Paris, aujourd'hui compris : depuis minuit (Paris) il y a 29 jours, ou 13 jours.
- Le panier moyen (total ÷ nombre de commandes, arrondi au centime) est calculé dans `SalesSummary` à partir des deux agrégats ; il vaut `null` sans aucune commande.
- Le graphique est dessiné en HTML et CSS (hauteur de barre via la variable CSS `--bar-size`), caché aux lecteurs d'écran (`aria-hidden`) et doublé d'un tableau de données dans un `<details>`.

### Fuseaux horaires

- PHP tourne en UTC dans le conteneur (`date.timezone` par défaut) : `new \DateTimeImmutable()` est en UTC, et Doctrine enregistre les dates **telles quelles, sans conversion**. Les colonnes `DATETIME` contiennent donc de l'heure UTC.
- La session MariaDB est en `SYSTEM` (UTC) ; `NOW()` n'est pas utilisé, les bornes sont calculées en PHP.
- Une borne calculée en heure de Paris (minuit) est convertie dans le fuseau de stockage avant d'être passée en paramètre (`OrderRepository::toStorageTimezone()`).
- `CONVERT_TZ()` avec des noms de fuseaux (`'UTC'`, `'Europe/Paris'`) a besoin des tables de fuseaux de MariaDB. L'image officielle `mariadb` les charge à l'initialisation ; sans elles, `CONVERT_TZ()` renvoie `NULL` et le test `DashboardServiceTest::testDailyRevenueUsesParisDays` échoue.
- Un décalage fixe (`'+01:00'`) a été écarté : il serait faux pendant l'heure d'été.

## Conséquences

- Les indicateurs restent rapides quel que soit le nombre de commandes ; une seule requête par indicateur.
- La requête par jour est spécifique à MariaDB/MySQL (`CONVERT_TZ`, `DATE`). Changer de base de données demanderait de la réécrire.
- Le fuseau de stockage dépend du fuseau par défaut de PHP. S'il changeait un jour, les dates déjà enregistrées seraient dans l'ancien fuseau : il faudrait fixer explicitement `date.timezone` (ou un type Doctrine qui convertit en UTC).
- Les lignes de commande dont le produit a été supprimé (`product_id` à `NULL`) ne figurent pas dans le top des ventes.
- Si le volume devenait très important, on pourrait ajouter un index sur `orders (status, paid_at)` ou passer à des statistiques pré-calculées (option 3).
