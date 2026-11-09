# ADR 0001 : Choix de la stack technique

- **Date** : 2026-11-09
- **Statut** : accepté

## Contexte

Projet e-commerce d'apprentissage, développé seul, en local uniquement. Le développeur souhaite travailler en PHP et apprendre l'architecture (couches, injection de dépendances, SOLID, tests). Un MVP doit être réalisé en une semaine.

## Options étudiées

1. **PHP sans framework** : très formateur, mais trop long (routeur, formulaires, sécurité à réécrire).
2. **Laravel** : très productif, mais sa « magie » (façades, Active Record) masque l'injection de dépendances et la séparation des couches.
3. **Symfony** : injection de dépendances explicite, Doctrine sépare entités et accès aux données, très demandé en France.

## Décision

- **Symfony 7.4 LTS** avec Doctrine, Twig et PHPUnit.
- **PHP 8.3**, identique à la version installée localement. Symfony 8.x exige PHP 8.4 ; la version LTS est maintenue jusqu'en novembre 2028 (correctifs) et 2029 (sécurité), ce qui suffit largement.
- **MariaDB 11.4**, base relationnelle classique de l'écosystème PHP.
- **Docker Compose** pour l'environnement : reproductible, isolé de la machine, et compétence recherchée.
- Rendu côté serveur avec **Twig** (pas de SPA pour l'instant).

## Conséquences

- Toutes les commandes PHP passent par le conteneur `php`.
- Pas de nouvelles fonctionnalités de Symfony 8.x ; la migration se fera vers la prochaine LTS (8.4) si besoin.
- Une API REST pourra être ajoutée plus tard sans remettre en cause la base.
