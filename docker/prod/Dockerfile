# Image de PRODUCTION, construite en plusieurs étapes (multi-stage build).
# Le Dockerfile de développement (docker/php/Dockerfile) n'est pas concerné.
#
# Construction depuis la racine du dépôt (le contexte doit contenir app/ et docker/) :
#   docker compose -f compose.prod.yaml build
# Les fichiers exclus du contexte sont listés dans Dockerfile.dockerignore (à côté de ce fichier).
#
# Étapes :
#   php_base : PHP-FPM + extensions + réglages de production (commune aux autres étapes)
#   build    : installe les dépendances, compile les ressources, prépare le cache (outils jetés ensuite)
#   php      : image finale de l'application, sans Composer ni outils de construction, utilisateur non root
#   nginx    : serveur web qui contient uniquement le dossier public/ (ressources compilées)

# ---------------------------------------------------------------------------
# Étape 1 : base commune
# ---------------------------------------------------------------------------
FROM php:8.3-fpm AS php_base

# Extensions PHP de l'application (les mêmes qu'en développement, sauf zip, utile seulement à Composer) :
# - pdo_mysql : connexion à MariaDB ; intl : formatage des prix et des dates ; opcache : cache du code compilé.
# Les paquets "-dev" (en-têtes C) ne servent qu'à compiler les extensions : on les supprime ensuite
# en gardant uniquement les bibliothèques dont les extensions ont besoin pour fonctionner (méthode
# recommandée par les images officielles PHP : ldd liste les bibliothèques réellement utilisées,
# dpkg-query retrouve le paquet de chacune ; les lignes « diversion » de dpkg-query ne sont pas des paquets).
RUN set -eux; \
    savedAptMark="$(apt-mark showmanual)"; \
    apt-get update; \
    apt-get install -y --no-install-recommends libicu-dev; \
    docker-php-ext-install -j"$(nproc)" intl pdo_mysql opcache; \
    apt-mark auto '.*' > /dev/null; \
    [ -z "$savedAptMark" ] || apt-mark manual $savedAptMark; \
    find /usr/local -type f -executable -exec ldd '{}' ';' \
        | awk '/=>/ { so = $(NF-1); if (index(so, "/usr/local/") == 1) { next }; gsub("^/(usr/)?", "", so); print so }' \
        | sort -u | xargs -r dpkg-query --search | cut -d: -f1 | grep -v '^diversion' | sort -u | xargs -r apt-mark manual; \
    apt-get purge -y --auto-remove -o APT::AutoRemove::RecommendsImportant=false; \
    rm -rf /var/lib/apt/lists/*

# Réglages PHP : on part du php.ini de production fourni par l'image (erreurs non affichées, journalisées),
# puis on ajoute nos fichiers (OPcache, sécurité) et la configuration du pool PHP-FPM.
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"
COPY docker/prod/php/app.ini $PHP_INI_DIR/conf.d/zz-app.ini
COPY docker/prod/php/opcache.ini $PHP_INI_DIR/conf.d/zz-opcache.ini
COPY docker/prod/php/fpm-pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

# Environnement de production : pas de débogage, pas de barre de débogage, pages d'erreur génériques.
ENV APP_ENV=prod \
    APP_DEBUG=0

WORKDIR /var/www/html

# ---------------------------------------------------------------------------
# Étape 2 : construction (Composer, ressources, cache)
# ---------------------------------------------------------------------------
FROM php_base AS build

# unzip permet à Composer de décompresser les paquets téléchargés.
RUN apt-get update \
    && apt-get install -y --no-install-recommends unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# 1) Les dépendances d'abord, seules : tant que composer.json/composer.lock ne changent pas,
#    Docker réutilise cette couche en cache au lieu de tout retélécharger.
#    --no-dev : ni PHPUnit, ni PHPStan, ni le profiler ; --no-scripts : la console n'est pas encore copiée.
COPY app/composer.json app/composer.lock app/symfony.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-progress --no-interaction

# 2) Le code de l'application.
COPY app/ ./

# 3) Préparation pour la production :
#    - chargeur de classes optimisé : une table "classe => fichier" générée une fois pour toutes
#      (--classmap-authoritative : PHP ne cherche jamais un fichier hors de cette table) ;
#    - dump-env prod : compile les fichiers .env versionnés (sans secret) en .env.local.php, plus rapide à lire ;
#      les vraies variables d'environnement, fournies au démarrage, restent prioritaires ;
#    - importmap:install puis asset-map:compile : copie les CSS/JS dans public/assets/ avec un nom versionné ;
#    - cache:warmup : le conteneur de services et les templates Twig sont compilés dans l'image.
#      Une fausse adresse de base suffit : Doctrine ne se connecte pas pendant cette étape.
RUN set -eux; \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction; \
    composer dump-env prod; \
    export APP_SECRET=build-only DATABASE_URL='mysql://build:build@127.0.0.1:3306/build?serverVersion=11.4.0-MariaDB&charset=utf8mb4'; \
    php bin/console importmap:install; \
    php bin/console asset-map:compile; \
    php bin/console cache:warmup; \
    rm -rf assets/vendor

# ---------------------------------------------------------------------------
# Étape 3 : image finale de l'application (PHP-FPM)
# ---------------------------------------------------------------------------
FROM php_base AS php

# Le code appartient à root et n'est donc pas modifiable par le processus PHP (www-data) :
# une faille dans l'application ne permettrait pas de réécrire le code.
# Seuls var/ (cache, sessions) et public/uploads/ (photos des produits) sont accessibles en écriture.
COPY --from=build /var/www/html /var/www/html
RUN mkdir -p var public/uploads \
    && chown -R www-data:www-data var public/uploads

# Exécution avec un utilisateur non root (www-data existe déjà dans l'image Debian).
USER www-data

EXPOSE 9000
CMD ["php-fpm"]

# ---------------------------------------------------------------------------
# Étape 4 : serveur web Nginx
# ---------------------------------------------------------------------------
FROM nginx:1.27-alpine AS nginx

COPY docker/prod/nginx/default.conf /etc/nginx/conf.d/default.conf

# Nginx ne sert que les fichiers statiques de public/ (ressources compilées, favicon…).
# Le dossier uploads/ est remplacé au démarrage par le volume partagé avec PHP.
COPY --from=build /var/www/html/public /var/www/html/public
