# ADR 0013 : Espace « Mon compte » (profil, mot de passe, adresse enregistrée)

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

L'entité `User` possède les colonnes `phone`, `address`, `postal_code` et `city`, mais aucune page ne permettait de les remplir. Au moment de commander, seul le nom du destinataire était prérempli.

Besoins :

- une page `/mon-compte` (connexion requise) avec deux formulaires indépendants : le profil (prénom, nom, téléphone, adresse) et le changement de mot de passe ;
- l'adresse du profil suit les mêmes règles que l'adresse de livraison (`ShippingAddress`), sans les dupliquer ;
- à la commande, l'adresse de livraison est préremplie avec celle du profil, et une case permet d'y enregistrer l'adresse saisie.

## Options étudiées

### Partager les règles de l'adresse

1. **Copier les contraintes** dans le DTO du profil : simple, mais deux endroits à modifier le jour où une règle change (par exemple les codes postaux d'outre-mer).
2. **Constantes** (`ShippingAddress::POSTAL_CODE_PATTERN`) utilisées dans les attributs des deux classes : la valeur est partagée, mais la liste des contraintes (Regex, Length…) reste écrite deux fois.
3. **Contraintes composées** (`Compound`, mécanisme standard de Symfony) : une classe par champ (`PostalCode`, `StreetAddress`, `City`) regroupe ses contraintes de format, et chaque DTO l'utilise comme un attribut ordinaire (`#[AppAssert\PostalCode]`). Aucun validateur à écrire.
4. **Un objet adresse commun** imbriqué dans les deux DTO (`Valid`) : partage complet, mais il impose un formulaire imbriqué et l'adresse n'a pas le même caractère obligatoire dans les deux cas.

### Changement de mot de passe : rester connecté

1. **Ne rien faire de plus** : le jeton de la session courante contient le même objet `User` que celui qui vient d'être modifié ; Symfony le réenregistre en session à la fin de la requête, avec le nouveau hash.
2. **`Security::login($user)`** : recrée explicitement le jeton. Mais Symfony ne change pas l'identifiant de session quand l'utilisateur reconnecté est le même (`SessionStrategyListener`), donc aucun gain de sécurité par rapport à l'option 1.
3. **Option 1 + `$session->migrate(true)`** : nouvel identifiant de session, l'ancien est détruit.

### Enregistrer l'adresse depuis la commande

1. **Dans le contrôleur**, après `placeOrder()` : de la logique métier dans le contrôleur.
2. **Dans `OrderService::placeOrder()`, dans la transaction** : un échec du profil annulerait la commande.
3. **Dans `OrderService::placeOrder()`, après la transaction**, erreurs de base de données journalisées.

## Décision

- **Contraintes composées** dans `src/Validator/` (option 3). Elles ne contiennent que les règles de **format** et acceptent une valeur vide. Le caractère obligatoire reste dans chaque DTO : `NotBlank` dans `ShippingAddress` (tout est obligatoire pour livrer), rien dans `ProfileData` (l'adresse du profil est facultative).
- **Deux DTO** liés aux formulaires, comme `ProductData` (ADR 0005) : `ProfileData` et `ChangePasswordData`. Une saisie invalide ne touche jamais l'entité `User` (ni l'en-tête de la page, ni un `flush()` ultérieur).
- **Une seule action** (`AccountController::index`) traite les deux formulaires. Chacun a son nom (`profile`, `change_password`) : `handleRequest()` ne soumet que celui dont le nom est présent dans la requête, l'autre n'est donc pas validé. Chacun a son propre jeton CSRF (`csrf_token_id` : `account_profile`, `account_password`), stocké en session.
- **Mot de passe actuel** vérifié par la contrainte `UserPassword` de Symfony (dans `Sequentially`, après `NotBlank`, pour n'afficher qu'un message). Le nouveau est haché par `UserPasswordHasherInterface` dans `AccountService::changePassword()`. Le DTO ne vit que le temps de la requête et `PasswordType` ne réaffiche jamais la saisie.
- **Rester connecté** : option 3. Les autres sessions du client, qui ont encore l'ancien hash, sont déconnectées par Symfony à leur prochaine requête (`ContextListener` compare le mot de passe en session à celui en base).
- **Adresse de la commande vers le profil** : option 3. La case est la propriété `ShippingAddress::$saveToProfile`. Le nom du destinataire n'est pas copié dans le profil (le destinataire peut être un tiers). `ShippingAddress::fromCustomer()` préremplit le formulaire de commande.

## Conséquences

- Changer une règle d'adresse se fait à un seul endroit, dans `src/Validator/`.
- Le prénom et le nom ont toujours leurs contraintes à deux endroits (entité `User` pour l'inscription, `ProfileData` pour le profil). On pourra les extraire de la même façon si une troisième utilisation apparaît.
- Un échec de l'enregistrement du profil après une commande est seulement visible dans les journaux : le client n'est pas prévenu que son adresse n'a pas été enregistrée.
- La commande garde sa propre copie de l'adresse : modifier le profil ne change ni les commandes passées ni leurs e-mails.
- Aucun e-mail n'est envoyé après un changement de mot de passe (amélioration possible, `symfony/mailer` est déjà installé).
