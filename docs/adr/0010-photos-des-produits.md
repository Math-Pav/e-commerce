# ADR 0010 : Photos des produits

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

Les produits étaient affichés avec un visuel de remplacement (une icône par catégorie). L'administrateur doit pouvoir envoyer une vraie photo, la remplacer ou la retirer. Accepter un fichier envoyé par un utilisateur est l'une des fonctionnalités les plus risquées d'un site : il faut choisir où ranger le fichier, sous quel nom, et comment s'assurer que c'est bien une image.

## Options étudiées

### Où stocker la photo

1. **Fichier sur disque dans `public/uploads/products/`, seul son nom en base** : Nginx sert le fichier directement, sans passer par PHP. Simple et rapide.
2. **Fichier hors de `public/`, servi par un contrôleur Symfony** : permet de contrôler l'accès, mais chaque image lancerait PHP. Inutile : les photos du catalogue sont publiques.
3. **Contenu de l'image en base (BLOB)** : base plus lourde, sauvegardes plus lentes, images servies par PHP.
4. **Bundle spécialisé (VichUploaderBundle)** : gère l'envoi automatiquement, mais c'est une dépendance de plus et beaucoup de « magie » pour un besoin simple.

### Comment vérifier le type du fichier

1. **Contrainte `Image` de Symfony avec `mimeTypes`** : la solution habituelle, mais elle nécessite le composant `symfony/mime`, qui n'est pas installé dans le projet.
2. **Extension PHP `fileinfo` (classe `finfo`)**, déjà disponible : elle lit les premiers octets du fichier (sa « signature ») pour en déduire le type, sans croire le nom ni le type annoncé par le navigateur.

## Décision

Stockage : option 1. Vérification : option 2 (pas de nouvelle dépendance).

- `Product::$imageFilename` (colonne `image_filename`, facultative) : nom du fichier, ou `null` pour garder le visuel de remplacement.
- `ProductData` porte le fichier envoyé (`$image`) et la case « Retirer la photo actuelle » (`$removeImage`). Si les deux sont remplis, la nouvelle photo l'emporte (elle remplace l'ancienne).
- Validation dans `ProductData` :
  - contrainte `File(maxSize: '2M')` : 2 Mo maximum ;
  - `Callback` `validateImageType()` : le contenu doit être du JPEG, du PNG ou du WebP, détecté par `ProductImageStorage::guessExtension()` (fileinfo). Le SVG est refusé : c'est du texte XML qui peut contenir du JavaScript, exécuté si l'on ouvre l'image dans le navigateur.
- `App\Service\ProductImageStorage` :
  - `store()` enregistre le fichier sous un nom **aléatoire** (`bin2hex(random_bytes(16))`, 32 caractères hexadécimaux) suivi de l'extension correspondant au **contenu** (`.jpg`, `.png`, `.webp`). Le nom d'origine n'est jamais utilisé : il pourrait contenir `../`, écraser un autre fichier, se terminer par `.php` ou révéler des informations ;
  - `remove()` supprime un fichier (avec `basename()` pour ne jamais sortir du dossier).
- `ProductService` supprime l'ancien fichier quand la photo est remplacée ou retirée, **après** l'enregistrement en base : si l'enregistrement échoue, le produit pointe toujours vers un fichier existant.
- Le dossier est un paramètre (`app.product_images_directory` dans `config/services.yaml`). En environnement de test, il pointe vers `var/test/uploads/products/` : les tests ne touchent jamais aux photos de développement, et ils vident ce dossier à la fin.
- `public/uploads/` est ignoré par Git : ce sont des données, pas du code.
- Affichage : le gabarit `product/_image.html.twig` affiche la photo (texte alternatif = nom du produit, recadrage `object-fit: cover`) ou, à défaut, le visuel de remplacement `product/_visual.html.twig`.

### Pourquoi un fichier PHP déposé dans `uploads/` ne s'exécuterait pas

La configuration Nginx actuelle (`docker/nginx/default.conf`) ne transmet à PHP-FPM que `/index.php` (bloc `location ~ ^/index\.php(/|$)`, marqué `internal`). Tout autre chemin se terminant par `.php` tombe dans le bloc `location ~ \.php$ { return 404; }`. Un fichier d'une autre extension (`.png`, `.phtml`…) est servi tel quel comme fichier statique, sans jamais être interprété. Vérifié à la main : `/uploads/products/x.php` répond 404, et un `.png` contenant du code PHP est renvoyé comme `image/png` sans être exécuté.

C'est une deuxième ligne de défense : la première reste la validation du contenu et le nom généré (qui se termine toujours par `.jpg`, `.png` ou `.webp`).

## Conséquences

- Les photos ne sont pas versionnées ni sauvegardées avec la base : recharger les fixtures laisse des fichiers orphelins dans `public/uploads/products/`, et une base restaurée peut référencer un fichier absent.
- Si l'enregistrement en base échoue juste après l'envoi, le nouveau fichier reste sur le disque (fichier orphelin, sans conséquence de sécurité).
- Une commande affiche la photo **actuelle** du produit (contrairement au prix et au nom, qui sont copiés au moment de l'achat).
- Limite de taille : Nginx accepte par défaut des requêtes de 1 Mo au maximum (`client_max_body_size`). Tant que ce réglage n'est pas modifié, une photo entre 1 et 2 Mo envoyée depuis le navigateur est refusée par Nginx (erreur 413) avant d'atteindre Symfony.
- Si `symfony/mime` est installé un jour, la vérification pourra être remplacée par la contrainte `Image(mimeTypes: [...])` de Symfony.
