# Image de base officielle : PHP 8.3 avec PHP-FPM
# (même version que ton PHP local, pour éviter les surprises)
FROM php:8.3-fpm

# Dépendances système, puis extensions PHP nécessaires à Symfony :
# - pdo_mysql : connexion à MariaDB via Doctrine
# - intl      : formatage des prix, dates, traductions
# - zip       : utilisé par Composer pour décompresser les paquets
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libicu-dev libzip-dev \
    && docker-php-ext-install pdo_mysql intl zip \
    && rm -rf /var/lib/apt/lists/*

# On récupère l'exécutable Composer depuis son image officielle
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Dossier de travail : c'est là que ton projet sera monté
WORKDIR /var/www/html
