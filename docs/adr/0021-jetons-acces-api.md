# ADR 0021 : Jetons d'accès personnels pour l'API « mes commandes »

- **Date** : 2026-11-14
- **Statut** : accepté

## Contexte

Un client veut consulter ses commandes depuis une application mobile, par l'API (`GET /api/v1/me/orders`). Le site authentifie ses clients par une **session** : après la connexion, le navigateur renvoie un cookie à chaque requête et le serveur retrouve l'utilisateur dans la session. Ce modèle convient mal à une application mobile : elle n'est pas un navigateur, ne gère pas les cookies comme lui, et ne doit pas conserver le mot de passe du client pour se reconnecter.

## Options étudiées

### Session ou jeton

| | Session + cookie (pare-feu `main`) | Jeton dans l'en-tête `Authorization` (pare-feu `api_me`) |
|---|---|---|
| Qui envoie l'identifiant ? | Le navigateur, **automatiquement**, à chaque requête vers le site | Le programme, **explicitement**, en ajoutant l'en-tête à chaque requête |
| État côté serveur | La session (fichier) contient l'utilisateur connecté | Aucun (stateless) : chaque requête est authentifiée par le jeton seul |
| Durée de vie | Celle de la session (fermeture du navigateur, déconnexion) | Fixée à la création (90 jours), révocable à tout moment |
| Adapté à | Un navigateur qui affiche des pages | Une application mobile, un script |
| Risque principal | CSRF (le cookie part même si la requête vient d'un autre site) | Vol du jeton (il donne accès sans mot de passe jusqu'à sa révocation) |

### Type de jeton

1. **JWT** (jeton signé contenant l'identité du client) : le serveur n'a rien à stocker, mais un JWT ne peut pas être révoqué avant son expiration sans une liste de révocation, et il faudrait une dépendance (`lexik/jwt-authentication-bundle`).
2. **Jeton opaque enregistré en base** (retenue) : une chaîne aléatoire sans signification, retrouvée en base à chaque requête. Révocation immédiate (on supprime la ligne), date de dernière utilisation visible par le client, aucune dépendance : l'authentificateur `access_token` est fourni par Symfony.

## Décision

### Un pare-feu dédié, sans session

`config/packages/security.yaml` déclare le pare-feu `api_me` (`^/api/v1/me`) **avant** `main` : `main` n'a pas de `pattern`, il prend toutes les adresses ; Symfony utilise le premier pare-feu qui correspond. `api_me` est `stateless: true` : aucune session n'est lue ni créée. Conséquence voulue : le cookie de session du site **n'ouvre pas** l'API, et le jeton n'ouvre pas le site. L'`access_control` exige `ROLE_USER` sur ces adresses, en plus de `#[IsGranted]` sur le contrôleur.

Chaîne d'authentification : l'authentificateur `access_token` lit l'en-tête `Authorization: Bearer <jeton>` (jamais un paramètre d'URL : une adresse est écrite dans les journaux du serveur), puis `App\Security\ApiAccessTokenHandler` retrouve le client. `App\Security\ApiAuthenticationHandler` produit les réponses 401 au format problem+json (ADR 0020), avec l'en-tête `WWW-Authenticate: Bearer` de la RFC 6750 :

- aucun jeton : point d'entrée du pare-feu (`entry_point`) ;
- jeton inconnu ou révoqué : « Jeton d'accès invalide ou révoqué. » (un jeton révoqué est supprimé, il est donc inconnu) ;
- jeton expiré : message distinct, pour que l'application invite le client à en créer un nouveau. Ce détail ne renseigne qu'une personne qui possède déjà le jeton.

### Génération et stockage du jeton

- **Génération** : `bin2hex(random_bytes(32))`, soit 256 bits d'entropie fournis par le générateur cryptographique du système (64 caractères hexadécimaux).
- **Stockage** : seule l'**empreinte SHA-256** est enregistrée (`api_token.token_hash`, unique). Le jeton en clair est montré une seule fois, dans la réponse qui suit sa création (sans redirection, donc sans passer par la session, avec `Cache-Control: no-store`). Une fuite de la base ne donne donc aucun jeton utilisable.
- **Recherche** : on calcule l'empreinte du jeton reçu et on cherche la ligne qui a cette empreinte (index unique).

**Pourquoi SHA-256 et pas `password_hash()` (bcrypt, argon2) ?** Un hachage lent protège un **mot de passe** : choisi par un humain, il est court et prévisible, et un attaquant qui vole la base essaie des milliards de mots de passe probables ; la lenteur rend cette attaque trop coûteuse. Un jeton de 256 bits aléatoires n'a pas de « valeurs probables » : même à des milliards d'essais par seconde, il faudrait bien plus que l'âge de l'univers pour retrouver un jeton à partir de son empreinte. La lenteur n'apporte donc rien. De plus, un hachage salé et lent (un sel différent par ligne) empêcherait de **retrouver** le jeton par son empreinte : il faudrait tester toutes les lignes une par une, à chaque requête. SHA-256, rapide et déterministe, permet la recherche par index.

- **Début du jeton** (`token_prefix`, 8 caractères) : affiché dans la liste pour que le client reconnaisse ses jetons. Il reste 224 bits inconnus, ce qui ne change rien à la sécurité.

### Règles

- Expiration : 90 jours après la création (`ApiTokenService::LIFETIME_DAYS`).
- 5 jetons actifs (non expirés) au plus par client (`ApiTokenService::MAX_ACTIVE_TOKENS`). Les jetons expirés restent listés, marqués « Expiré », jusqu'à ce que le client les supprime.
- Révocation : `POST` avec jeton CSRF depuis « Mon compte » ; la ligne est supprimée. Un client ne peut révoquer que ses propres jetons (404 sinon).
- Date de dernière utilisation mise à jour à chaque requête authentifiée.
- Un client ne lit que ses propres commandes : la commande est cherchée par référence **et** par client ; celle d'un autre client donne la même 404 qu'une commande inexistante.

### Le jeton n'apparaît jamais dans les journaux

- Il n'est accepté que dans un en-tête, pas dans l'URL (les URL sont journalisées).
- Aucun message d'erreur ne le contient ; le paramètre de `getUserBadgeFrom()` est marqué `#[\SensitiveParameter]`, ce qui le masque dans les traces d'exception.
- En développement, le profileur Symfony enregistre les en-têtes de chaque requête : `ApiAccessTokenHandler` le désactive pour toute requête qui présente un jeton.

### Pourquoi pas de protection CSRF sur l'API

Une attaque CSRF repose sur l'envoi **automatique** d'un identifiant par le navigateur : une page piégée sur un autre site déclenche une requête vers notre site, et le navigateur y joint de lui-même le cookie de session de la victime. Le jeton CSRF prouve que la requête vient bien d'une page de notre site.

Avec un jeton dans l'en-tête `Authorization`, rien n'est envoyé automatiquement : le navigateur ne connaît pas le jeton et un autre site ne peut pas le lire pour l'ajouter. Une requête forgée arrive donc sans jeton et reçoit une 401. La protection CSRF n'aurait rien à protéger. Cela suppose que le pare-feu `api_me` ignore réellement la session (`stateless: true`, vérifié par un test) : si le cookie du site ouvrait l'API, le risque CSRF reviendrait. Les pages « Mon compte » qui créent et révoquent les jetons, elles, utilisent la session et gardent leur protection CSRF.

## Conséquences

- Nouveau dossier `src/Security/` pour les classes branchées sur le système de sécurité de Symfony.
- `ApiProblemListener::createResponse()` construit les réponses problem+json, réutilisé par les réponses 401 du pare-feu.
- Une requête authentifiée fait une écriture en base (date de dernière utilisation) : acceptable au volume du projet.
- Le jeton perdu ne peut pas être réaffiché : le client en crée un nouveau et révoque l'ancien.
- La limite de requêtes de l'API (60 par minute et par IP, ADR 0020) s'applique aussi à ces adresses.
