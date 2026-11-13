# Modèle de données du MVP

Dernière mise à jour : 2026-11-13

## Diagramme

```mermaid
erDiagram
    CATEGORY ||--o{ PRODUCT : contient
    USER ||--o{ ORDERS : passe
    ORDERS ||--|{ ORDER_ITEM : contient
    PRODUCT |o--o{ ORDER_ITEM : "référencé par"

    CATEGORY {
        int id PK
        string name UK
        string slug UK
    }
    PRODUCT {
        int id PK
        int category_id FK
        string name
        string slug UK
        text description
        int price
        int stock
        bool is_active
        string image_filename
        datetime created_at
    }
    USER {
        int id PK
        string email UK
        json roles
        string password
        string first_name
        string last_name
        string phone
        string address
        string postal_code
        string city
        datetime created_at
    }
    ORDERS {
        int id PK
        int user_id FK
        string reference UK
        string status
        int total_amount
        string shipping_full_name
        string shipping_address
        string shipping_postal_code
        string shipping_city
        datetime created_at
        datetime paid_at
        datetime shipped_at
        datetime delivered_at
        datetime cancelled_at
    }
    ORDER_ITEM {
        int id PK
        int order_id FK
        int product_id FK
        string product_name
        int unit_price
        int quantity
    }
```

## Entités

### Category (`category`)

| Colonne | Type | Contraintes |
|---|---|---|
| id | integer | clé primaire, auto-incrémentée |
| name | string(100) | obligatoire, unique (ADR 0008) |
| slug | string(120) | obligatoire, unique, généré à partir du nom à la création puis figé |

### Product (`product`)

| Colonne | Type | Contraintes |
|---|---|---|
| id | integer | clé primaire |
| category_id | integer | clé étrangère → category, obligatoire |
| name | string(150) | obligatoire |
| slug | string(170) | obligatoire, unique, généré à partir du nom à la création puis figé (ADR 0004) |
| description | text | facultatif |
| price | integer | obligatoire, en centimes, ≥ 0 |
| stock | integer | obligatoire, défaut 0, ≥ 0 |
| is_active | boolean | obligatoire, défaut `true` ; `false` = masqué du catalogue sans être supprimé |
| image_filename | string(255) | facultatif ; nom du fichier de la photo dans `public/uploads/products/`, généré aléatoirement (ADR 0010) ; vide = visuel de remplacement |
| created_at | datetime_immutable | obligatoire |

### User (`user`)

| Colonne | Type | Contraintes |
|---|---|---|
| id | integer | clé primaire |
| email | string(180) | obligatoire, unique (identifiant de connexion) |
| roles | json | obligatoire (ex. `["ROLE_ADMIN"]`) |
| password | string(255) | obligatoire, **hash** du mot de passe |
| first_name | string(50) | obligatoire |
| last_name | string(50) | obligatoire |
| phone | string(20) | facultatif |
| address | string(255) | facultatif |
| postal_code | string(10) | facultatif |
| city | string(100) | facultatif |
| created_at | datetime_immutable | obligatoire |

### Order (`orders`)

`order` est un mot réservé en SQL (`ORDER BY`) : la table s'appelle `orders`.

| Colonne | Type | Contraintes |
|---|---|---|
| id | integer | clé primaire |
| user_id | integer | clé étrangère → user, obligatoire |
| reference | string(20) | obligatoire, unique (ex. `CMD-2026-00042`) |
| status | string(20) | obligatoire, valeurs de l'enum `OrderStatus` |
| total_amount | integer | obligatoire, en centimes |
| shipping_full_name | string(100) | obligatoire, copie au moment de la commande |
| shipping_address | string(255) | obligatoire, copie |
| shipping_postal_code | string(10) | obligatoire, copie |
| shipping_city | string(100) | obligatoire, copie |
| created_at | datetime_immutable | obligatoire |
| paid_at | datetime_immutable | facultatif (vide tant que non payée) |
| shipped_at | datetime_immutable | facultatif (vide tant que non expédiée) |
| delivered_at | datetime_immutable | facultatif (vide tant que non livrée) |
| cancelled_at | datetime_immutable | facultatif (vide sauf si annulée) |

États (`OrderStatus`) : `pending` (en attente de paiement), `paid` (payée), `shipped` (expédiée), `delivered` (livrée), `cancelled` (annulée).

Transitions autorisées (méthodes de `Order`, voir ADR 0007) :

```
pending ──markAsPaid()──▶ paid ──markAsShipped()──▶ shipped ──markAsDelivered()──▶ delivered
   │
   └──cancel()──▶ cancelled   (le stock est remis par OrderService::cancel())
```

### OrderItem (`order_item`)

| Colonne | Type | Contraintes |
|---|---|---|
| id | integer | clé primaire |
| order_id | integer | clé étrangère → orders, obligatoire |
| product_id | integer | clé étrangère → product, **facultatif** (`SET NULL` si le produit est supprimé) |
| product_name | string(150) | obligatoire, copie au moment de l'achat |
| unit_price | integer | obligatoire, en centimes, copie au moment de l'achat |
| quantity | integer | obligatoire, ≥ 1 |

## Relations

| Relation | Question 1 | Question 2 | Type | Clé étrangère |
|---|---|---|---|---|
| Category ↔ Product | Une catégorie a combien de produits ? Plusieurs | Un produit a combien de catégories ? Une | un-à-plusieurs | `product.category_id` |
| User ↔ Order | Un utilisateur a combien de commandes ? Plusieurs | Une commande a combien d'utilisateurs ? Un | un-à-plusieurs | `orders.user_id` |
| Order ↔ OrderItem | Une commande a combien de lignes ? Plusieurs (au moins une) | Une ligne appartient à combien de commandes ? Une | un-à-plusieurs | `order_item.order_id` |
| Product ↔ OrderItem | Un produit apparaît dans combien de lignes ? Plusieurs | Une ligne concerne combien de produits ? Un (ou aucun s'il a été supprimé) | un-à-plusieurs | `order_item.product_id` |

## Décisions

- **Prix en centimes (`integer`)** : évite les erreurs d'arrondi des nombres à virgule.
- **Copies dans `orders` et `order_item`** (adresse, nom du produit, prix) : une commande est un document historique, elle ne doit pas changer si le produit, son prix ou l'adresse du client changent ensuite.
- **`total_amount` stocké** plutôt que recalculé : il fige le montant réellement facturé.
- **Adresse dans `user`** : suffisant pour le MVP. Une entité `Address` séparée sera envisagée si l'on veut plusieurs adresses par client.
- **Une catégorie ne peut être supprimée que si elle ne contient aucun produit** (même désactivé) : `product.category_id` est obligatoire.
- **Pas d'entité `Cart`** : le panier est stocké en session pour le MVP.
- **Photo des produits : seul le nom du fichier est en base** : l'image elle-même est un fichier sur disque (`public/uploads/products/`), servi directement par Nginx.
- **Codes postaux et téléphones en `string`** : ce ne sont pas des nombres (zéros initiaux, aucun calcul).
