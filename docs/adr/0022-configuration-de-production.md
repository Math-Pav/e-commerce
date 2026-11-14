# ADR 0022 : Configuration de production (image multi-étapes, secrets par l'environnement)

- **Date** : 2026-11-14
- **Statut** : accepté

## Contexte

L'environnement Docker du projet est pensé pour le développement : le code est monté depuis l'hôte, les dépendances de développement sont installées, Adminer et Mailpit sont exposés, le débogage est actif et les mots de passe de la base sont écrits en clair dans `compose.yaml`. Rien de cela ne convient à un serveur public. Le site ne sera pas mis en ligne tout de suite, mais la configuration de production doit être prête, testée et documentée, sans modifier l'environnement de développement.

## Options étudiées

### Organisation des fichiers

1. **Modifier `compose.yaml` et le Dockerfile existants** avec des variantes par environnement : un seul jeu de fichiers, mais chaque modification risque de casser le développement.
2. **Un `compose.override.yaml` de développement et un `compose.yaml` commun** : idiomatique, mais oblige à réorganiser l'existant.
3. **Fichiers de production séparés** (retenue) : `compose.prod.yaml` et `docker/prod/`. Le développement ne change pas d'un octet ; la contrepartie est un peu de duplication (configuration Nginx).

### Construction de l'image

1. **Une seule étape** : Composer, `unzip` et les en-têtes de compilation restent dans l'image finale.
2. **Plusieurs étapes** (retenue) : une étape `build` installe les dépendances et compile ressources et cache, puis l'image finale ne copie que le résultat. Une étape `nginx` récupère le dossier `public/` compilé, car Nginx ne peut plus lire le code sur l'hôte.

### Secrets

1. **Variables d'environnement** lues dans un fichier hors du dépôt (retenue) : standard Docker, rien de plus à apprendre.
2. **Coffre-fort de Symfony** (secrets chiffrés et versionnés, une seule clé privée à protéger) : utile à plusieurs développeurs ou serveurs, une notion de plus pour un seul serveur.
3. **Secrets Docker** (fichiers montés dans `/run/secrets`) : Symfony sait les lire (`%env(file:...)%`), mais il faudrait modifier la configuration de l'application.

## Décision

- `docker/prod/Dockerfile` construit quatre étapes : `php_base` (extensions, `php.ini-production`, OPcache), `build` (`composer install --no-dev`, chargeur de classes `--classmap-authoritative`, `dump-env prod`, `asset-map:compile`, `cache:warmup`), `php` (image finale) et `nginx`.
- L'image PHP tourne avec l'utilisateur `www-data` ; le code appartient à root et n'est pas modifiable par PHP, seuls `var/` et `public/uploads/` le sont.
- OPcache ne vérifie plus les dates des fichiers (`validate_timestamps = 0`) et précharge les classes de Symfony : le code ne change jamais dans un conteneur, un déploiement crée un nouveau conteneur.
- `compose.prod.yaml` ne contient que des références `${VARIABLE}` ; les variables obligatoires utilisent `${VARIABLE:?message}` pour refuser de démarrer sans elles. Chaque service a une vérification de santé ; PHP attend que la base soit prête et Nginx attend PHP.
- Les photos des produits sont dans un volume partagé entre PHP (écriture) et Nginx (lecture seule).
- HTTPS est confié à un proxy placé devant Nginx, hors de ce dépôt (Caddy, Traefik ou Certbot selon l'hébergement).

## Conséquences

- L'environnement de développement est inchangé.
- Chaque version déployée est une image figée et reproductible ; revenir en arrière consiste à relancer l'image précédente (et, si besoin, à restaurer la base).
- Les migrations restent une étape manuelle du déploiement (`docs/deploiement.md`).
- Les sessions vivent dans le conteneur PHP : un redéploiement déconnecte les clients et vide les paniers. Acceptable tant que le site n'a pas de trafic réel.
- La mise en ligne réelle demande encore un vrai paiement, un vrai serveur SMTP, des pages d'erreur personnalisées, une commande d'annulation automatique et des sauvegardes : liste dans `docs/deploiement.md`.
