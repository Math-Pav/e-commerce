# ADR 0020 : API REST du catalogue en lecture seule

- **Date** : 2026-11-14
- **Statut** : accepté

## Contexte

Une application mobile ou un site partenaire veut afficher nos produits. Il faut une API JSON en lecture seule : liste filtrée, triée et paginée, fiche d'un produit avec ses variantes, liste des catégories. Le catalogue du site sait déjà filtrer, trier et paginer (`CatalogQuery`, `ProductRepository::findCatalogPage()`, ADR 0011).

Contrairement aux pages du site, une API est utilisée par des programmes que nous ne contrôlons pas et que nous ne pouvons pas mettre à jour : **son contrat (adresses, paramètres, champs du JSON) ne doit pas changer sans prévenir**.

## Options étudiées

### Construire l'API

1. **API Platform** : génère routes, documentation OpenAPI, pagination et filtres à partir d'attributs. Puissant, mais beaucoup de « magie » et une grosse dépendance.
2. **À la main** (retenue) : trois contrôleurs-actions, des objets de sortie, deux écouteurs. Chaque choix est visible dans le code, ce qui est l'objectif du projet.

### Produire le JSON

1. **Sérialiser les entités** : le JSON suit les entités. Ajouter une colonne la publie (fuite possible : `stock`, `isActive`, `imageFilename`…), la renommer casse les applications clientes.
2. **Objets de sortie dédiés** (retenue) : `ProductOutput`, `ProductDetailOutput`, `VariantOutput`, `CategoryOutput`, `Price`, `ProductListOutput` (`src/Api/Output/`). Seuls leurs champs publics sont publiés. `CatalogOutputFactory` est le seul endroit qui convertit une entité en objet de sortie : si une entité change, on adapte la conversion et le JSON reste le même.

### Encoder les objets de sortie

1. **symfony/serializer** : autorisé, mais ses apports (groupes, conversion des noms, dates) ne servent pas ici.
2. **`json_encode()`** (retenue, via `AbstractController::json()` qui l'utilise quand le Serializer n'est pas installé) : il encode les propriétés publiques d'un objet et la valeur d'une énumération typée. Une dépendance de moins. Si un besoin apparaît (dates, noms en `snake_case`), le Serializer pourra être ajouté sans changer les objets de sortie.

## Décision

### Le versionnement par l'URL : `/api/v1`

Toutes les routes commencent par `/api/v1`. Le numéro de version fait partie du contrat :

- **Changement rétrocompatible** (ajouter un champ, une route, un paramètre facultatif, une valeur de tri) : fait directement dans la v1. Un client bien écrit ignore les champs qu'il ne connaît pas.
- **Changement cassant** (supprimer ou renommer un champ, changer son type ou son sens, rendre un paramètre obligatoire) : on crée `/api/v2` à côté de la v1. Les applications existantes continuent d'appeler la v1 et migrent quand elles le peuvent ; la v1 est retirée plus tard, après annonce.

Alternatives écartées : version dans un en-tête (`Accept: application/vnd.boutique.v1+json`), plus « pure » mais invisible dans un navigateur et plus difficile à tester ; pas de version du tout, ce qui oblige à ne jamais rien casser ou à casser tous les clients d'un coup.

### Le contrat

- **Identifiants publics** : le `slug` pour un produit ou une catégorie, la référence (`sku`) pour une variante. Aucun identifiant technique (`id` en base) n'est exposé : il ne dit rien au client et révèlerait des informations (nombre de produits, ordre de création).
- **Prix** en centimes (`int`), toujours accompagnés de la devise : `{"amount": 1490, "currency": "EUR"}`. Pas de nombre décimal (erreurs d'arrondi des `float`) et pas d'unité à deviner.
- **Disponibilité** sous forme d'état (`available`, `low_stock`, `out_of_stock`, énumération `Availability`), jamais le stock exact : c'est une donnée commerciale interne. Seuil du stock faible : `ProductVariant::LOW_STOCK_THRESHOLD`. Un produit prend le meilleur état de ses variantes actives (même règle que « Rupture de stock » sur le site).
- **Paramètres propres à l'API**, en anglais (`q`, `category`, `sort`, `page`) et associés aux tris de `CatalogSort` par `CatalogQueryParameters::SORTS`. Le site garde ses paramètres en français ; renommer un paramètre du site ne casse pas la v1.
- **Paramètre invalide = 400**. Le site remplace en silence une valeur invalide par la valeur par défaut (confortable pour un visiteur) ; un programme qui envoie `sort=prix` doit au contraire l'apprendre, sinon il affiche un mauvais tri sans le savoir. Une catégorie inconnue est aussi un 400. Une page au-delà de la dernière renvoie une liste vide (le site, lui, redirige).
- **Enveloppe** : les données sont toujours sous `data` ; la liste ajoute `meta` (`page`, `perPage`, `total`, `lastPage`) et `links` (`self`, `previous`, `next`, adresses absolues, `null` s'il n'y a pas de page).
- **Pas de note moyenne** : le projet n'a pas de système d'avis. On n'expose pas un champ toujours `null` : un client finirait par en dépendre sans qu'il ait jamais de sens. Il pourra être ajouté dans la v1 le jour où les avis existeront, car ajouter un champ est rétrocompatible.

### Les erreurs : application/problem+json (RFC 9457)

`ApiProblemListener` (événement `kernel.exception`) remplace, pour les adresses `/api/...`, la page d'erreur HTML par un objet standard :

```json
{"type": "about:blank", "title": "Not Found", "status": 404, "detail": "Le produit « agenda-2026 » n'existe pas."}
```

`type: about:blank` signifie que le code HTTP suffit à décrire le problème. Seules les exceptions HTTP (levées volontairement, avec un message destiné au client) exposent leur message dans `detail` ; une autre exception devient un 500 sans détail, pour ne rien révéler du code. Les en-têtes de l'exception sont conservés (`Retry-After` pour 429, `Allow` pour 405).

Un produit désactivé renvoie 404, comme un produit inconnu : l'API ne révèle pas qu'il existe.

### La limite de requêtes : 60 par minute et par adresse IP

Composant officiel `symfony/rate-limiter` (`config/packages/rate_limiter.yaml`), appliqué par `ApiRateLimitListener` sur `kernel.request` à toutes les adresses `/api/...`, avant le routage (les requêtes vers une adresse inexistante comptent aussi). Au-delà : 429 problem+json avec `Retry-After`.

- **Fenêtre glissante** (`sliding_window`) plutôt que fixe : avec une fenêtre fixe, un client peut envoyer 60 requêtes à 12 h 00 min 59 s puis 60 à 12 h 01 min 00 s.
- **Adresse IP** : `Request::getClientIp()`. Aucun proxy de confiance n'est configuré, c'est donc l'adresse de la connexion : un en-tête `X-Forwarded-For` inventé par le client ne permet pas de contourner la limite. Si le site passe un jour derrière un proxy, il faudra déclarer `trusted_proxies`, sinon tous les clients partageront l'adresse du proxy.
- **Stockage** : pool `cache.rate_limiter` (fichiers). `symfony/lock` n'est pas installé : en 7.4, `lock_factory: auto` s'en passe s'il est absent. Sans verrou, deux requêtes strictement simultanées peuvent être comptées une seule fois ; pour une limite de confort comme celle-ci, cette imprécision est acceptable.

### Réutilisation du catalogue

`CatalogController` construit une `CatalogQuery` et appelle `findCatalogPage()` et `loadVariants()` comme le site : mêmes filtres, mêmes tris, même nombre de requêtes SQL, même taille de page (12). Les catégories viennent de `CatalogFilterService` (catégories ayant un produit actif, en cache, ADR 0019).

## Conséquences

- Une modification d'entité ne change pas l'API : adapter `CatalogOutputFactory` si nécessaire, sans toucher aux objets de sortie.
- Un changement cassant du JSON ou des paramètres impose une v2.
- `docs/api.md` documente le contrat ; il doit être mis à jour avec chaque évolution de l'API.
- L'API est publique et en lecture seule : pas d'authentification, pas de CSRF (aucune action ne modifie de données, et aucune session n'est utilisée).
- Le stockage de la limite est vidé avant chaque test avec le cache applicatif (`ClearCachePoolsExtension`) : les tests ne se gênent pas.
