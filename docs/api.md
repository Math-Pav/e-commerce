# API du catalogue (v1)

API REST publique, en lecture seule, pour afficher nos produits dans une application mobile ou sur un site partenaire. Les choix de conception sont expliqués dans `docs/adr/0020-api-catalogue.md`.

Les adresses `/api/v1/me/…` donnent au client ses propres commandes, avec un jeton d'accès personnel (voir [Mes commandes](#mes-commandes-jeton-daccès), `docs/adr/0021-jetons-acces-api.md`).

- **Adresse de base** : `http://localhost:8080/api/v1` (environnement local)
- **Méthode** : `GET` uniquement (toute autre méthode : 405)
- **Format** : JSON (`application/json`), erreurs en `application/problem+json`
- **Authentification** : aucune pour le catalogue ; jeton d'accès (`Authorization: Bearer <jeton>`) pour `/api/v1/me/…`
- **Limite** : 60 requêtes par minute et par adresse IP (voir [Erreurs](#erreurs))

## Conventions

- **Identifiants** : le `slug` d'un produit ou d'une catégorie, la référence `sku` d'une variante.
- **Prix** : un objet `{"amount": 1490, "currency": "EUR"}`, `amount` en **centimes** (1490 = 14,90 €).
- **Disponibilité** (`availability`) : `available` (disponible), `low_stock` (stock faible, 5 unités ou moins), `out_of_stock` (rupture). Le stock exact n'est jamais exposé.
- **Enveloppe** : les données sont sous `data`.
- **Champs absents** : il n'y a pas de note moyenne, le site n'a pas de système d'avis. Elle pourra être ajoutée à la v1 (ajouter un champ ne casse pas les clients).
- **Évolutions** : de nouveaux champs peuvent apparaître dans la v1 ; un client doit ignorer ceux qu'il ne connaît pas. Une suppression ou un renommage passera par une v2.

## GET /api/v1/products

Liste paginée des produits en vente (les produits désactivés n'apparaissent jamais), 12 par page.

| Paramètre | Valeurs | Défaut | Rôle |
|---|---|---|---|
| `q` | texte, 100 caractères au plus | aucun | Recherche dans le nom et la description |
| `category` | slug d'une catégorie existante | aucun | Filtre par catégorie |
| `sort` | `name`, `price_asc`, `price_desc`, `newest` | `name` | Tri (le prix trié est le prix « à partir de ») |
| `page` | entier ≥ 1 | `1` | Numéro de page ; au-delà de la dernière, `data` est vide |

Un paramètre vide (`?q=`) est ignoré. Une valeur invalide renvoie **400**.

Champs d'un produit : `slug`, `name`, `category` (`slug`, `name`), `price` (le plus petit prix de ses variantes actives), `hasVariedPrices` (`true` si ses variantes n'ont pas toutes le même prix : afficher « à partir de »), `availability` (le meilleur état de ses variantes), `imageUrl` (adresse absolue de la photo, ou `null`).

`meta` : `page`, `perPage`, `total` (nombre total de produits correspondant), `lastPage`. `links` : `self`, `previous`, `next` (adresses absolues, `null` s'il n'y a pas de page).

Exemple : `GET /api/v1/products?category=stylos&sort=price_asc`

```json
{
  "data": [
    {
      "slug": "stylo-a-bille-bleu",
      "name": "Stylo à bille bleu",
      "category": { "slug": "stylos", "name": "Stylos" },
      "price": { "amount": 150, "currency": "EUR" },
      "hasVariedPrices": false,
      "availability": "available",
      "imageUrl": null
    },
    {
      "slug": "lot-de-10-surligneurs",
      "name": "Lot de 10 surligneurs",
      "category": { "slug": "stylos", "name": "Stylos" },
      "price": { "amount": 790, "currency": "EUR" },
      "hasVariedPrices": false,
      "availability": "available",
      "imageUrl": null
    },
    {
      "slug": "stylo-plume",
      "name": "Stylo plume",
      "category": { "slug": "stylos", "name": "Stylos" },
      "price": { "amount": 3490, "currency": "EUR" },
      "hasVariedPrices": false,
      "availability": "low_stock",
      "imageUrl": null
    }
  ],
  "meta": { "page": 1, "perPage": 12, "total": 3, "lastPage": 1 },
  "links": {
    "self": "http://localhost:8080/api/v1/products?category=stylos&sort=price_asc",
    "previous": null,
    "next": null
  }
}
```

Erreurs : **400** (paramètre invalide, catégorie inconnue), **429**.

## GET /api/v1/products/{slug}

Fiche d'un produit en vente : les champs de la liste, plus `description` (texte ou `null`) et `variants`, ses variantes actives dans l'ordre de création. Chaque variante : `sku`, `label` (`null` pour la variante unique d'un produit sans déclinaison), `price` (son prix propre ou, à défaut, celui du produit), `availability`.

Exemple : `GET /api/v1/products/stylo-plume`

```json
{
  "data": {
    "slug": "stylo-plume",
    "name": "Stylo plume",
    "description": "Plume en acier inoxydable, livré avec deux cartouches.",
    "category": { "slug": "stylos", "name": "Stylos" },
    "price": { "amount": 3490, "currency": "EUR" },
    "hasVariedPrices": false,
    "availability": "low_stock",
    "imageUrl": null,
    "variants": [
      {
        "sku": "PRD-00021",
        "label": null,
        "price": { "amount": 3490, "currency": "EUR" },
        "availability": "low_stock"
      }
    ]
  }
}
```

Erreurs : **404** (produit inconnu ou désactivé), **429**.

## GET /api/v1/categories

Catégories ayant au moins un produit en vente, par nom. Le `slug` sert de valeur au paramètre `category` de `/api/v1/products`. Aucun paramètre.

Exemple : `GET /api/v1/categories`

```json
{
  "data": [
    { "slug": "carnets", "name": "Carnets" },
    { "slug": "mugs-et-tasses", "name": "Mugs et tasses" },
    { "slug": "stylos", "name": "Stylos" }
  ]
}
```

Erreurs : **429**.

## Mes commandes (jeton d'accès)

### Obtenir un jeton

Le client se connecte au site, ouvre **Mon compte › Gérer mes jetons d'accès** (`/mon-compte/jetons-api`), donne un nom au jeton (« Mon téléphone ») et le crée. Le jeton (64 caractères hexadécimaux) n'est affiché **qu'une fois** : il faut le copier aussitôt. Il est valable 90 jours ; 5 jetons actifs au plus par client. La même page liste les jetons (nom, début du jeton, dates de création, de dernière utilisation et d'expiration) et permet de les révoquer.

### Utiliser le jeton

Chaque requête envoie le jeton dans l'en-tête `Authorization` (jamais dans l'adresse) :

```
GET /api/v1/me/orders
Authorization: Bearer 3f9c…
```

Aucune session, aucun cookie : la connexion au site n'ouvre pas ces adresses, et le jeton n'ouvre pas le site. Sans jeton valide, la réponse est **401** (voir [Erreurs](#erreurs)).

### GET /api/v1/me/orders

Les commandes du client, les plus récentes en premier, sans pagination.

Champs d'une commande : `reference` (identifiant), `status` (`pending`, `paid`, `shipped`, `delivered`, `cancelled`), `statusLabel` (intitulé en français), `createdAt` (date ISO 8601 avec fuseau), `total` (montant payé, remise déduite), `itemCount` (nombre d'articles).

```json
{
  "data": [
    {
      "reference": "CMD-2026-00042",
      "status": "paid",
      "statusLabel": "Payée",
      "createdAt": "2026-11-14T10:30:00+01:00",
      "total": { "amount": 2980, "currency": "EUR" },
      "itemCount": 2
    }
  ]
}
```

Erreurs : **401**, **429**.

### GET /api/v1/me/orders/{reference}

Détail d'une commande du client. La commande d'un autre client répond **404**, comme une commande inexistante.

Champs : ceux de la liste (sauf `itemCount`), plus `paidAt`, `shippedAt`, `deliveredAt`, `cancelledAt` (date ISO 8601 ou `null`), `subtotal` (avant remise), `discount`, `discountCode` (ou `null`), `shippingAddress` (`fullName`, `address`, `postalCode`, `city`) et `items` : `productName`, `variantLabel` (ou `null`), `unitPrice` (prix au moment de l'achat), `quantity`, `subtotal`.

```json
{
  "data": {
    "reference": "CMD-2026-00042",
    "status": "paid",
    "statusLabel": "Payée",
    "createdAt": "2026-11-14T10:30:00+01:00",
    "paidAt": "2026-11-14T10:31:12+01:00",
    "shippedAt": null,
    "deliveredAt": null,
    "cancelledAt": null,
    "subtotal": { "amount": 2980, "currency": "EUR" },
    "discount": { "amount": 0, "currency": "EUR" },
    "discountCode": null,
    "total": { "amount": 2980, "currency": "EUR" },
    "shippingAddress": { "fullName": "Camille Martin", "address": "12 rue des Lilas", "postalCode": "69001", "city": "Lyon" },
    "items": [
      {
        "productName": "Mug noir mat",
        "variantLabel": null,
        "unitPrice": { "amount": 1490, "currency": "EUR" },
        "quantity": 2,
        "subtotal": { "amount": 2980, "currency": "EUR" }
      }
    ]
  }
}
```

Erreurs : **401**, **404**, **429**.

## Erreurs

Toutes les erreurs suivent le format `application/problem+json` (RFC 9457) : `type` (toujours `about:blank`), `title` (intitulé du code HTTP), `status`, et `detail` (explication en français, absente pour une erreur 500).

| Code | Cas |
|---|---|
| 400 | Paramètre invalide : `sort` inconnu, `page` non entière ou < 1, `q` trop long, catégorie inconnue, paramètre envoyé sous forme de tableau (`?q[]=`) |
| 401 | `/api/v1/me/…` : jeton absent, inconnu, révoqué ou expiré. L'en-tête `WWW-Authenticate: Bearer realm="api"` est ajouté (avec `error="invalid_token"` si un jeton a été envoyé) |
| 404 | Produit inconnu ou désactivé, commande inexistante ou d'un autre client, adresse inexistante sous `/api/` |
| 405 | Méthode autre que `GET` (en-tête `Allow: GET`) |
| 429 | Plus de 60 requêtes en une minute depuis la même adresse IP ; l'en-tête `Retry-After` donne le nombre de secondes à attendre |
| 500 | Erreur interne (aucun détail exposé) |

Exemples réels :

```json
{"type": "about:blank", "title": "Bad Request", "status": 400, "detail": "Le paramètre « sort » doit valoir : name, price_asc, price_desc, newest."}
```

```json
{"type": "about:blank", "title": "Not Found", "status": 404, "detail": "Le produit « agenda-2026 » n'existe pas."}
```

```json
{"type": "about:blank", "title": "Unauthorized", "status": 401, "detail": "Jeton d'accès expiré : créez-en un nouveau dans « Mon compte »."}
```

```json
{"type": "about:blank", "title": "Too Many Requests", "status": 429, "detail": "Trop de requêtes : la limite est de 60 par minute. Réessayez plus tard."}
```
