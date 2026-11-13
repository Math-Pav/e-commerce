# ADR 0014 : Analyse statique (PHPStan) et formatage automatique (PHP-CS-Fixer)

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

Le code est couvert par les tests, mais :

- rien ne vérifie les types de façon systématique : une méthode qui peut renvoyer `null` utilisée comme si elle renvoyait toujours un objet, un tableau dont le contenu n'est pas décrit… Les tests ne détectent ces erreurs que sur les chemins qu'ils parcourent ;
- le style varie d'un fichier à l'autre (fichiers écrits à la main, générés par le MakerBundle ou par les missions de l'agent) : ordre des imports, espaces autour de `.`, `declare(strict_types=1);` oublié ;
- les fins de ligne sont mélangées : 46 fichiers en CRLF, les autres en LF, alors que `app/.editorconfig` demande LF. Chaque fois qu'un outil réécrit un fichier CRLF en LF, le diff montre le fichier entier comme modifié (voir le commit « Rétablit les fins de ligne CRLF des fichiers modifiés »).

## Options étudiées

### Analyse statique

1. **PHPStan** avec les extensions officielles `phpstan-symfony` (type des services du conteneur, des paramètres) et `phpstan-doctrine` (mapping des entités, repositories, DQL).
2. **Psalm** : comparable, mais moins utilisé dans l'écosystème Symfony et ses extensions Symfony/Doctrine sont moins suivies.
3. **Aucun outil** : on compte sur les tests seuls.

### Formatage

1. **PHP-CS-Fixer** avec le jeu de règles `@Symfony` : il corrige le code automatiquement (pas seulement un rapport) et ses règles sont celles du code de Symfony lui-même.
2. **PHP_CodeSniffer** (PSR-12) : surtout orienté détection ; la correction automatique (`phpcbf`) couvre moins de règles.

### Fins de ligne

1. **Laisser chaque fichier tel quel** en désactivant la règle `line_ending` du fixer. Essayé : cela ne marche pas. Les autres règles réécrivent les espaces avec des LF, et 33 fichiers CRLF se sont retrouvés avec des fins de ligne **mélangées** dans un même fichier.
2. **Formater, puis remettre les fichiers concernés en CRLF** : un diff de formatage propre une fois, mais le problème revient à chaque `composer cs-fix`.
3. **Tout passer en LF une fois pour toutes**, dans un commit qui ne contient que ce changement, et l'imposer avec un `.gitattributes` (`* text=auto eol=lf`).

## Décision

- **PHPStan niveau 8** sur `src/` et `tests/`, configuré dans `app/phpstan.dist.neon`, **sans fichier baseline** : toutes les erreurs ont été corrigées dans le code. Le niveau 9 (règles strictes sur `mixed`) n'est pas visé pour l'instant.
- Extension Doctrine : `allowNullablePropertyForRequiredField: true`, pour accepter le modèle généré par `make:entity` (`private ?string $name = null;` sur une colonne `NOT NULL`, la valeur n'est nulle qu'avant le premier enregistrement). `tests/object-manager.php` fournit l'EntityManager à PHPStan.
- Extension Symfony : elle lit le conteneur compilé de l'environnement `dev` (`var/cache/dev/App_KernelDevDebugContainer.xml`). `composer stan` lance d'abord `cache:warmup` pour qu'il existe toujours.
- Un `@phpstan-ignore` n'est accepté qu'avec un commentaire qui explique pourquoi l'erreur est un faux positif.
- **PHP-CS-Fixer** configuré dans `app/.php-cs-fixer.dist.php`, sur `src/` et `tests/` : règles `@Symfony`, `declare_strict_types` (règle « risquée », d'où `setRiskyAllowed(true)`) et `ordered_imports` (ordre alphabétique : classes, fonctions, constantes). Les fichiers des recettes Flex (`public/index.php`, `config/`…) et les migrations générées ne sont pas formatés : les modifier compliquerait les mises à jour des recettes.
- **Fins de ligne** : option 3. Un commit dédié convertit les 46 fichiers CRLF en LF (vérifiable avec `git show --ignore-cr-at-eol --stat` : seules trois lignes finales changent, celles des fichiers qui se terminaient par un CR isolé). Le `.gitattributes` à la racine garantit que Git enregistre toujours des LF, même si un éditeur enregistre en CRLF.
- **Scripts Composer** (dans `app/composer.json`) : `composer stan`, `composer cs` (vérification sans modification), `composer cs-fix` et `composer check`, qui enchaîne PHPStan, la vérification du formatage et PHPUnit. `composer check` fait partie des vérifications obligatoires avant chaque commit.
- Le premier formatage a été appliqué dans un commit séparé qui ne contient que du formatage.

## Conséquences

- Les erreurs de type (valeur nulle non vérifiée, tableau mal décrit) sont détectées avant l'exécution, y compris sur les chemins que les tests ne parcourent pas.
- Les tests vérifient explicitement ce qu'ils supposent (`self::assertNotNull($product)` après un `findOneBy()`) : un échec donne un message clair au lieu d'une erreur « appel de méthode sur null ».
- Quelques invariants sont maintenant explicites dans le code : un produit doit être enregistré pour aller dans le panier, un utilisateur doit avoir un e-mail pour être identifié. Les violer lève une `\LogicException` au lieu d'une `TypeError` ou d'une valeur vide.
- Le style ne se discute plus en relecture : `composer cs-fix` l'applique. Deux règles de `@Symfony` changent visiblement l'écriture : les comparaisons « Yoda » (`null === $order`) et `single_line_throw` (un `throw` tient sur une ligne). Après relecture, les deux sont conservées : on applique `@Symfony` sans exception, pour que le code suive les mêmes conventions que celui de Symfony.
- Le fixer affiche un avertissement : il tourne en PHP 8.3 alors que `composer.json` accepte PHP 8.2 (`"php": ">=8.2"`). Sans conséquence ; aligner la contrainte sur 8.3 le ferait disparaître.
- Deux dépendances de développement de plus à maintenir (PHPStan et PHP-CS-Fixer, avec les extensions de PHPStan) ; elles n'existent pas en production.
- `composer check` ajoute une quinzaine de secondes à la vérification avant chaque commit.
