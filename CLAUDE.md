# CLAUDE.md

Instructions permanentes pour Claude sur ce projet. À lire avant toute intervention.

## Contexte

- Projet personnel d'apprentissage : un site e-commerce en PHP.
- Développeur : étudiant en 3e année de développement Fullstack.
- **Objectif principal : apprendre** l'architecture, la conception et les bonnes pratiques. Livrer vite est secondaire.
- Pas de mise en ligne prévue pour l'instant : environnement local uniquement.

## Stack

- **PHP 8.3** (PHP-FPM) avec **Composer 2**
- **Symfony 7.4 LTS**
- **Doctrine ORM** + **Doctrine Migrations** pour la base de données
- **MariaDB 11.4**
- **Twig** pour le rendu des pages côté serveur (pas de SPA pour le moment)
- **PHPUnit** pour les tests
- **Docker Compose** pour l'environnement local (php, nginx, database, adminer)
- **Git** pour le versionnement

## Organisation du dépôt

- `app/` : l'application Symfony
- `docker/` : configuration des conteneurs (Dockerfile PHP, configuration Nginx)
- `compose.yaml` : définition des services
- `docs/` : documentation technique et décisions (`docs/adr/`)

Toutes les commandes PHP, Composer et Symfony s'exécutent **dans le conteneur** :
`docker compose exec php <commande>` (ex. `docker compose exec php bin/console cache:clear`).

## Périmètre du MVP

Inclus : catégories, catalogue, fiche produit, inscription/connexion, panier, commande, paiement **simulé**, historique des commandes.

Hors périmètre pour l'instant (ne pas implémenter sans demande explicite) : administration, recherche avancée, variantes de produits, codes promo, avis, e-mails, vrai paiement.

## Architecture

Structure Symfony standard, organisée en couches :

- `src/Controller/` : reçoit la requête HTTP, appelle un service (ou directement un repository pour une simple lecture), renvoie une réponse. **Aucune logique métier.**
- `src/Service/` : logique métier (panier, commande, paiement).
- `src/Repository/` : requêtes en base. Aucune requête Doctrine en dehors des repositories.
- `src/Entity/` : entités Doctrine (données + règles simples propres à l'entité).
- `src/Form/` : formulaires Symfony et leur validation.
- `templates/` : vues Twig. Pas de logique métier dans les templates.

Principes :

- Injection de dépendances par le constructeur, gérée par le conteneur Symfony. Jamais de `new` sur un service.
- Dépendre d'une interface uniquement quand plusieurs implémentations existent réellement (ex. `PaymentProviderInterface`). Pas d'interface « au cas où ».
- Pas de design pattern ajouté sans besoin concret et justifié.
- Toute décision d'architecture importante est documentée dans `docs/adr/` (une décision par fichier).

## Règles métier importantes

- Les prix sont stockés en **centimes, sous forme d'entier** (`int`). Jamais de `float` pour de l'argent.
- Une ligne de commande (`OrderItem`) **copie le prix** du produit au moment de l'achat.
- La validation d'une commande et la décrémentation du stock se font **dans une même transaction**.
- Le stock ne peut jamais devenir négatif.

## Conventions

- Code (classes, méthodes, variables) en **anglais** ; commentaires et documentation en **français**.
- Respect de PSR-12 et des conventions Symfony.
- Classes en `PascalCase`, méthodes et variables en `camelCase`, tables et colonnes en `snake_case`.
- Suffixes explicites : `ProductController`, `CartService`, `ProductRepository`, `ProductType` (formulaire).
- Typage strict : `declare(strict_types=1);`, types sur tous les paramètres et retours.
- Méthodes courtes, noms explicites, pas de code mort ni de commentaires évidents.
- Messages de commit courts et explicites, au présent (ex. `Ajoute l'entité Product`).

## Sécurité

- Mots de passe hachés via le système de sécurité de Symfony. Jamais en clair.
- Protection CSRF active sur tous les formulaires.
- Toute entrée utilisateur est validée (contraintes de validation Symfony).
- Accès contrôlés par rôles (`ROLE_USER`, `ROLE_ADMIN`) et vérification qu'un utilisateur n'accède qu'à ses propres commandes.
- Aucune requête SQL construite par concaténation : uniquement Doctrine ou requêtes paramétrées.
- Aucun secret dans le dépôt : utiliser `.env.local` (ignoré par Git).

## Dépendances

- Ne jamais ajouter de dépendance Composer sans l'expliquer et obtenir mon accord.
- Préférer les composants officiels Symfony aux bibliothèques tierces.
- Uniquement des dépendances gratuites et open source.

## Tests

- Tests écrits **avec chaque fonctionnalité**, pas à la fin du projet.
- Tests unitaires pour la logique métier des services (panier, commande, calcul des totaux).
- Tests fonctionnels (WebTestCase) pour les parcours importants (connexion, ajout au panier, commande).
- Les tests ne dépendent pas d'un vrai service externe (paiement simulé).

## Collaboration avec Claude

- Rôle attendu : mentor technique et développeur senior.
- Avancer étape par étape. Ne jamais générer tout le projet ou une grosse fonctionnalité d'un seul coup.
- Pour chaque fonctionnalité : objectif, fonctionnement, approche, fichiers concernés, implémentation guidée, concepts clés, méthode de test.
- Claude écrit le code et explique les points importants ; après chaque fonctionnalité, il me pose une ou deux questions de compréhension.
- Expliquer tout concept nouveau ; ne pas supposer que je le connais.
- Proposer des alternatives avant toute décision technique majeure.
- Signaler les erreurs de conception et contester mes choix avec des arguments.
- Choisir la solution la plus simple qui répond au besoin.
- Mettre à jour ce fichier et `docs/` quand une décision change.

## Travail avec l'agent autonome

- L'agent `symfony-dev` (`.claude/agents/symfony-dev.md`) peut développer une fonctionnalité seul.
- Il travaille toujours sur une branche `feature/<nom>`, jamais sur `master`, et ne commite que si tous les tests passent.
- Il ne fusionne pas et ne pousse pas : je relis la branche (ou la relis avec Claude), je réponds aux questions de compréhension, puis je fusionne moi-même.
