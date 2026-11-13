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
- **Symfony Translation** pour traduire en français les messages par défaut de Symfony (site en français uniquement)
- **Symfony Mailer** pour les e-mails clients (envoi synchrone ; **Mailpit** capture les e-mails en développement, `null://null` en test)
- **Symfony Mime** pour vérifier le type des fichiers envoyés (photos des produits)
- **dompdf** pour convertir en PDF le template Twig des factures (`docs/adr/0018-factures.md`)
- **Symfony Cache** : pool `computed.cache` avec étiquettes pour les calculs coûteux, invalidé par un écouteur Doctrine (voir `docs/adr/0019-cache-applicatif.md`)
- **Symfony Profiler** (développement) pour mesurer requêtes et temps de chaque page
- **PHPUnit** pour les tests
- **PHPStan** (niveau 8, extensions Symfony et Doctrine) pour l'analyse statique et **PHP-CS-Fixer** (règles `@Symfony`) pour le formatage, en dépendances de développement (voir `docs/adr/0014-analyse-statique-et-formatage.md`)
- **Docker Compose** pour l'environnement local (php, nginx, database, adminer, mailpit : interface sur http://localhost:8025)
- **Git** pour le versionnement

## Organisation du dépôt

- `app/` : l'application Symfony
- `docker/` : configuration des conteneurs (Dockerfile PHP, configuration Nginx)
- `compose.yaml` : définition des services
- `docs/` : documentation technique et décisions (`docs/adr/`)

Toutes les commandes PHP, Composer et Symfony s'exécutent **dans le conteneur** :
`docker compose exec php <commande>` (ex. `docker compose exec php bin/console cache:clear`).

## Périmètre du MVP

Inclus : catégories, catalogue (recherche simple, tri, pagination), fiche produit avec photo, variantes de produits (couleur, format… : stock et prix facultatif par variante), inscription/connexion, espace « Mon compte » (profil, adresse, changement de mot de passe), panier, codes promo (un par commande, voir `docs/adr/0016-codes-promo.md`), commande, annulation par le client, paiement **simulé**, facture PDF de chaque commande payée (`docs/adr/0018-factures.md`), historique des commandes, e-mails clients, administration (tableau de bord, produits et leurs variantes, commandes, catégories, codes promo).

Hors périmètre pour l'instant (ne pas implémenter sans demande explicite) : recherche avancée (plein texte, facettes), avis, e-mails pour l'administrateur, envoi asynchrone (Messenger), vrai paiement, avoirs.

## Architecture

Structure Symfony standard, organisée en couches :

- `src/Controller/` : reçoit la requête HTTP, appelle un service (ou directement un repository pour une simple lecture), renvoie une réponse. **Aucune logique métier.**
- `src/Service/` : logique métier (panier, commande, paiement).
- `src/Repository/` : requêtes en base. Aucune requête Doctrine en dehors des repositories.
- `src/Entity/` : entités Doctrine (données + règles simples propres à l'entité).
- `src/Form/` : formulaires Symfony et leur validation.
- `src/EventListener/` : écouteurs d'événements (Doctrine, noyau), ex. invalidation du cache.
- `src/Validator/` : contraintes de validation réutilisables (contraintes composées partagées par plusieurs DTO, ex. `PostalCode`).
- `templates/` : vues Twig. Pas de logique métier dans les templates.

Principes :

- Injection de dépendances par le constructeur, gérée par le conteneur Symfony. Jamais de `new` sur un service.
- Dépendre d'une interface uniquement quand plusieurs implémentations existent réellement (ex. `PaymentProviderInterface`). Pas d'interface « au cas où ».
- Pas de design pattern ajouté sans besoin concret et justifié.
- Toute décision d'architecture importante est documentée dans `docs/adr/` (une décision par fichier).

## Règles métier importantes

- Les prix sont stockés en **centimes, sous forme d'entier** (`int`). Jamais de `float` pour de l'argent.
- Une ligne de commande (`OrderItem`) **copie le prix** du produit au moment de l'achat ; la commande copie de même le code promo utilisé et le montant de la remise.
- La validation d'une commande et la décrémentation du stock se font **dans une même transaction**.
- Le stock ne peut jamais devenir négatif.
- Une facture est créée dans la transaction du paiement, avec un numéro chronologique sans trou ; elle est immuable (jamais modifiée ni supprimée). Les prix sont TTC (TVA 20 %) ; le HT se calcule avec `VatCalculator`.
- Le stock est porté par les **variantes** (`ProductVariant`), jamais par le produit. Tout produit a au moins une variante ; un produit sans déclinaison a une variante par défaut sans libellé, invisible pour le client (voir `docs/adr/0017-variantes-de-produits.md`).

## Conventions

- Code (classes, méthodes, variables) en **anglais** ; commentaires et documentation en **français**.
- Interface du site en **français** (locale `fr`) : nos textes sont écrits en français directement dans les templates et le code, sans fichiers de traduction ; les messages par défaut de Symfony (validation, sécurité) sont traduits par `symfony/translation` (voir `docs/adr/0006-locale-francaise.md`).
- Respect de PSR-12 et des conventions Symfony ; le formatage est appliqué par PHP-CS-Fixer (`composer cs-fix`), pas à la main.
- Fins de ligne **LF** pour tous les fichiers (imposées par `.gitattributes`).
- Règles `@Symfony` appliquées sans exception, dont les comparaisons « Yoda » (`null === $order`) et les `throw` sur une seule ligne.
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
- Fichiers envoyés : type vérifié d'après le contenu (JPEG, PNG, WebP ; jamais de SVG), nom aléatoire, liste blanche d'extensions, stockage dans `public/uploads/` (ignoré par Git). Voir `docs/adr/0010-photos-des-produits.md`.

## Dépendances

- Ne jamais ajouter de dépendance Composer sans l'expliquer et obtenir mon accord.
- Préférer les composants officiels Symfony aux bibliothèques tierces.
- Uniquement des dépendances gratuites et open source.

## Tests

- Tests écrits **avec chaque fonctionnalité**, pas à la fin du projet.
- Tests unitaires pour la logique métier des services (panier, commande, calcul des totaux).
- Tests fonctionnels (WebTestCase) pour les parcours importants (connexion, ajout au panier, commande).
- Les tests ne dépendent pas d'un vrai service externe (paiement simulé).
- Avant chaque commit, `docker compose exec php composer check` doit passer : il enchaîne PHPStan (`composer stan`), la vérification du formatage (`composer cs`) et PHPUnit. `composer cs-fix` corrige le formatage.
- Les mêmes vérifications tournent sur GitHub à chaque push et pull request (`.github/workflows/ci.yml`, voir `docs/adr/0015-integration-continue.md`).
- Pas de fichier baseline PHPStan ; un `@phpstan-ignore` n'est accepté qu'avec un commentaire qui explique pourquoi l'erreur est un faux positif.

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
