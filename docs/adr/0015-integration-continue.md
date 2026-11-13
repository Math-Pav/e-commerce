# ADR 0015 : Intégration continue avec GitHub Actions

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

`composer check`, les linters Symfony et `doctrine:schema:validate` ne tournent que si le développeur pense à les lancer. Une erreur peut donc arriver sur GitHub sans être vue.

## Options étudiées

1. **GitHub Actions** : intégré au dépôt, gratuit pour un dépôt public (quota gratuit pour un privé), configuré par un simple fichier YAML versionné.
2. **Hook Git local (pre-push)** : ne s'exécute que sur la machine qui l'a installé et peut être contourné (`--no-verify`).

## Décision

Un workflow `.github/workflows/ci.yml`, déclenché à chaque push et pull request, rejoue toutes les vérifications sur une machine neuve : PHP 8.3 (extensions du Dockerfile), service MariaDB 11.4 jetable, base de test + migrations + fixtures, puis `composer check`, `lint:twig`, `lint:yaml`, `lint:container`, `doctrine:schema:validate` et `composer audit`.

Le workflow n'utilise pas Docker Compose : `setup-php` installe la même version de PHP et les mêmes extensions, plus rapidement qu'une construction d'image.

Les identifiants de la base sont écrits en clair dans le fichier : c'est une base jetable, accessible uniquement depuis le job, qui ne contient que les fixtures. Un vrai secret (clé d'API, mot de passe de production) irait dans les **secrets GitHub** (Settings > Secrets and variables > Actions) et serait lu avec `${{ secrets.NOM }}` ; GitHub le masque alors dans les journaux.

## Conséquences

- Chaque push affiche un statut (vert ou rouge) sur GitHub et dans le badge du README.
- La CI ne remplace pas les vérifications locales avant commit : elle les garantit.
