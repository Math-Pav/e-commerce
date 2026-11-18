# Déploiement en production

Ce document décrit la configuration de production préparée dans le dépôt et ce qu'il reste à faire avant une vraie mise en ligne. **Le site n'est pas en ligne** : tout est prêt pour être déployé plus tard. La décision est expliquée dans `docs/adr/0022-configuration-de-production.md`.

## Fichiers concernés

| Fichier | Rôle |
|---|---|
| `compose.prod.yaml` | Services de production : `php`, `nginx`, `database` (ni Adminer ni Mailpit) |
| `docker/prod/Dockerfile` | Image multi-étapes : construit l'image PHP et l'image Nginx |
| `docker/prod/Dockerfile.dockerignore` | Fichiers exclus de la construction (secrets locaux, tests, `vendor/`…) |
| `docker/prod/php/opcache.ini` | OPcache réglé pour la production (préchargement, pas de vérification des fichiers) |
| `docker/prod/php/app.ini` | Réglages PHP (erreurs journalisées et jamais affichées, fuseau horaire) |
| `docker/prod/php/fpm-pool.conf` | Nombre de processus PHP-FPM |
| `docker/prod/nginx/default.conf` | Nginx : compression, cache navigateur des ressources, en-têtes de sécurité |
| `docker/prod/prod.env.example` | Modèle des variables de production, **sans aucun secret** |

L'environnement de développement (`compose.yaml`, `docker/php/Dockerfile`, `docker/nginx/default.conf`) n'est pas modifié.

## Différences entre développement et production

| | Développement (`compose.yaml`) | Production (`compose.prod.yaml`) |
|---|---|---|
| Code | Monté depuis `app/` (modifiable à chaud) | Copié dans l'image (figé, une version = une image) |
| Dépendances | Toutes, y compris PHPUnit, PHPStan, profiler | `composer install --no-dev` |
| `APP_ENV` / débogage | `dev`, barre de débogage, traces d'erreur | `prod`, pages d'erreur génériques |
| Utilisateur PHP | Ton utilisateur Linux | `www-data` (non root), code en lecture seule |
| OPcache | Vérifie les fichiers à chaque requête | Code gardé en mémoire, préchargement |
| Ressources CSS/JS | Servies à la volée par AssetMapper | Compilées une fois (`asset-map:compile`), cache navigateur d'un an |
| Outils | Adminer, Mailpit | Aucun |
| Base de données | Port interne, mots de passe `app`/`root` | Non exposée, mots de passe fournis par l'environnement |
| Santé, redémarrage | Non | `healthcheck` sur chaque service, `restart: unless-stopped` |

## Secrets

Un secret (clé `APP_SECRET`, mot de passe de la base, identifiants SMTP, clé du prestataire de paiement) ne doit **jamais** être écrit dans un fichier versionné : Git conserve tout l'historique, un secret commité une fois doit être considéré comme divulgué.

### Ce que fait ce dépôt : variables d'environnement

`compose.prod.yaml` ne contient que des références `${VARIABLE}`. Docker Compose les remplace au lancement par les valeurs de l'environnement, lues dans un fichier **placé hors du dépôt** :

```bash
sudo mkdir -p /etc/ecommerce
sudo cp docker/prod/prod.env.example /etc/ecommerce/prod.env
sudo chmod 600 /etc/ecommerce/prod.env      # lisible par root seulement
sudo nano /etc/ecommerce/prod.env           # remplacer chaque « a-remplacer »
```

La syntaxe `${APP_SECRET:?message}` arrête le lancement si la variable manque : on ne démarre jamais la production avec une valeur par défaut connue de tous.

Symfony lit ces variables comme celles des fichiers `.env`, et les **vraies variables d'environnement sont prioritaires** sur les fichiers `.env`. L'image contient seulement `app/.env` (versionné, sans secret), compilé en `.env.local.php` par `composer dump-env prod` ; les fichiers `.env.local` et `.env.*.local` de ta machine sont exclus de l'image.

### L'alternative : le coffre-fort de secrets de Symfony

Symfony fournit un coffre-fort (*secrets vault*) : les secrets sont **chiffrés** et les fichiers chiffrés peuvent être commités.

```bash
docker compose exec php bin/console secrets:generate-keys --env=prod   # crée la paire de clés dans config/secrets/prod/
docker compose exec php bin/console secrets:set APP_SECRET --random --env=prod
docker compose exec php bin/console secrets:set MAILER_DSN --env=prod
```

- La clé publique (`prod.encrypt.public.php`) et les valeurs chiffrées sont versionnées.
- La clé privée (`prod.decrypt.private.php`) ne l'est **jamais** (déjà ignorée par `app/.gitignore`). En production, elle est fournie par la variable `SYMFONY_DECRYPTION_SECRET` (son contenu encodé en base64).

| | Variables d'environnement (retenu) | Coffre-fort Symfony |
|---|---|---|
| Où vivent les secrets | Fichier hors du dépôt sur le serveur | Dans le dépôt, chiffrés |
| Secrets à protéger sur le serveur | Tous | Un seul : la clé de déchiffrement |
| Historique des changements | Non | Oui (Git) |
| Simplicité | Très simple, standard Docker | Une notion de plus à maîtriser |

Pour un seul serveur et un seul développeur, les variables d'environnement suffisent. Le coffre-fort devient intéressant avec plusieurs développeurs ou plusieurs serveurs.

### Générer un APP_SECRET propre

`APP_SECRET` sert à signer les jetons CSRF, les liens signés et les cookies « se souvenir de moi ». Il doit être long, aléatoire et propre à la production (jamais celui de `.env.dev`) :

```bash
openssl rand -hex 32
# ou, sans openssl :
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

On obtient 64 caractères hexadécimaux (32 octets aléatoires). Le changer invalide les sessions et formulaires en cours : on le génère une fois, puis on n'y touche plus sauf en cas de fuite.

Les mots de passe de la base se génèrent de la même façon (`openssl rand -hex 24`). Un mot de passe contenant `@ : / ? #` devrait être encodé, car il est inséré dans l'URL `DATABASE_URL` : les caractères hexadécimaux évitent ce problème.

## Liste de contrôle avant mise en ligne

### Points bloquants

- [ ] **HTTPS.** Nginx n'écoute qu'en HTTP (port 80). Placer devant lui un proxy qui gère les certificats Let's Encrypt (Caddy ou Traefik, qui les renouvellent automatiquement, ou Nginx + Certbot sur l'hôte), puis :
  - publier Nginx sur un port local (`HTTP_PORT=8080`) que seul le proxy atteint ;
  - indiquer l'adresse IP du proxy dans `SYMFONY_TRUSTED_PROXIES`, sinon Symfony croit que la requête est en HTTP et génère des liens `http://` ;
  - activer HSTS sur le proxy (`Strict-Transport-Security`) une fois HTTPS vérifié.
- [ ] **Nom de domaine dans les e-mails.** `DEFAULT_URI` doit valoir l'adresse publique en HTTPS (`https://www.exemple.fr`) : elle sert aux liens absolus des e-mails. L'adresse d'expédition `app.mailer.sender_email` (`app/config/services.yaml`) vaut aujourd'hui `boutique@atelier-papier.test`, un domaine réservé qui n'existe pas : à remplacer par une adresse du vrai domaine.
- [ ] **Vrai serveur de messagerie.** `MAILER_DSN` doit pointer vers un vrai serveur SMTP (prestataire d'envoi ou hébergeur). Configurer SPF, DKIM et DMARC dans la zone DNS du domaine, sinon les e-mails finissent en spam. Envoyer un e-mail de test (inscription) avant d'ouvrir le site.
- [ ] **Vrai prestataire de paiement.** `FakePaymentProvider` accepte tout paiement jusqu'à 100 € (ADR 0003). Écrire une nouvelle implémentation de `PaymentProviderInterface` pour le prestataire choisi (Stripe, PayPal, Mollie…), avec ses clés en variables d'environnement et la vérification de ses notifications (webhooks). Le site ne doit pas être ouvert au public avant.
- [ ] **Premier compte administrateur.** Les fixtures n'existent pas en production (dépendance de développement). Créer son compte par l'inscription, puis lui donner le rôle administrateur en base (voir « Commandes de déploiement », étape 6).
- [ ] **Données légales.** Les informations du vendeur sur les factures (`app.invoice.*` dans `app/config/services.yaml`) sont fictives.

### Exploitation

- [ ] **Sauvegardes de la base.** Les données vivent dans le volume `ecommerce-prod_db_data`. Programmer une sauvegarde quotidienne, la copier **hors du serveur**, en garder plusieurs jours, et tester une restauration au moins une fois :
  ```bash
  # crontab de l'hôte : chaque nuit à 3 h
  0 3 * * * cd /srv/ecommerce && docker compose -f compose.prod.yaml --env-file /etc/ecommerce/prod.env exec -T database sh -c 'mariadb-dump -uroot -p"$MARIADB_ROOT_PASSWORD" --single-transaction "$MARIADB_DATABASE"' | gzip > /var/backups/ecommerce/db-$(date +\%F).sql.gz
  ```
  Sauvegarder aussi le volume `ecommerce-prod_uploads` (photos des produits). Les factures sont générées à la demande à partir de la base : la sauvegarde de la base suffit pour elles.
- [ ] **Tâche cron d'annulation automatique.** **Cette commande n'existe pas encore dans le projet.** Les commandes non payées gardent aujourd'hui leur stock réservé jusqu'à ce que le client les annule. Il faudra développer une commande console (par exemple `app:orders:cancel-unpaid`) qui annule les commandes en attente depuis plus de N heures et remet le stock, puis la programmer :
  ```bash
  */15 * * * * cd /srv/ecommerce && docker compose -f compose.prod.yaml --env-file /etc/ecommerce/prod.env exec -T php bin/console app:orders:cancel-unpaid
  ```
- [ ] **Rotation des journaux.** PHP et Nginx écrivent dans la sortie de leur conteneur (`docker compose logs`). `compose.prod.yaml` limite ces journaux à 5 fichiers de 10 Mo par conteneur (`x-logging`). Les journaux du proxy HTTPS et des sauvegardes, sur l'hôte, sont à confier à `logrotate`.
- [ ] **Mise à jour des dépendances.** Une fois par mois, et à chaque alerte de sécurité :
  - `docker compose exec php composer audit` (en développement) : failles connues dans les paquets ;
  - `composer outdated` : paquets en retard, mis à jour sur une branche, tests verts, puis redéploiement ;
  - reconstruire les images avec `--pull` pour récupérer les correctifs de sécurité de PHP, Debian, Nginx et MariaDB ;
  - Symfony 7.4 est une version LTS : correctifs de sécurité jusqu'en novembre 2029.
- [ ] **Pages d'erreur personnalisées.** Le projet n'en a pas encore : en production, une erreur 404 ou 500 affiche la page générique de Symfony (en anglais, sans aucun détail technique). Créer `app/templates/bundles/TwigBundle/Exception/error404.html.twig` et `error.html.twig` aux couleurs du site.
- [ ] **Pare-feu du serveur.** N'ouvrir que les ports 22 (SSH, par clé uniquement), 80 et 443. La base de données n'est publiée sur aucun port.

### À savoir

- Les sessions sont stockées dans le conteneur PHP : un redéploiement (nouveau conteneur) déconnecte les clients connectés. Le panier étant en session (ADR 0002), il est perdu aussi. Pour l'éviter plus tard : stocker les sessions en base de données (`PdoSessionHandler`).
- Les migrations ne sont pas lancées automatiquement au démarrage : c'est une étape explicite du déploiement, qu'on peut relire avant.
- Nginx tourne avec l'image officielle (processus maître root, processus de travail non root) ; l'image `nginxinc/nginx-unprivileged` permettrait de le faire tourner entièrement sans root.

## Commandes de déploiement, dans l'ordre

Toutes les commandes se lancent depuis la racine du dépôt cloné sur le serveur (par exemple `/srv/ecommerce`). Pour raccourcir :

```bash
alias dcp='docker compose -f compose.prod.yaml --env-file /etc/ecommerce/prod.env'
```

### Première installation

```bash
# 1. Récupérer le code (version précise, jamais une branche de travail)
git clone <adresse-du-depot> /srv/ecommerce && cd /srv/ecommerce
git checkout master

# 2. Préparer les secrets (voir « Secrets »)
sudo cp docker/prod/prod.env.example /etc/ecommerce/prod.env && sudo chmod 600 /etc/ecommerce/prod.env

# 3. Construire les images (--pull : dernières images de base, avec leurs correctifs)
dcp build --pull

# 4. Démarrer (la base d'abord, puis PHP, puis Nginx, chacun attend que le précédent soit en bonne santé)
dcp up -d
dcp ps                       # les trois services doivent être « healthy »

# 5. Créer les tables
dcp exec php bin/console doctrine:migrations:migrate -n

# 6. Après s'être inscrit sur le site, donner le rôle administrateur à son compte
dcp exec database sh -c 'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD" "$MARIADB_DATABASE" \
  -e "UPDATE user SET roles = JSON_ARRAY(\"ROLE_ADMIN\") WHERE email = \"moi@exemple.fr\""'
```

### Mise à jour (nouvelle version)

```bash
# 1. Sauvegarder la base AVANT toute mise à jour (commande de la section « Sauvegardes »)
# 2. Récupérer la nouvelle version
git pull
# 3. Reconstruire les images
dcp build --pull
# 4. Remplacer les conteneurs PHP et Nginx (la base n'est pas recréée, ses données sont dans le volume)
dcp up -d
# 5. Appliquer les nouvelles migrations
dcp exec php bin/console doctrine:migrations:migrate -n
# 6. Vérifier
dcp ps
dcp logs --tail=50 php nginx
```

Pendant l'étape 4, le site est indisponible quelques secondes. L'étape 5 suit immédiatement : une migration doit donc rester compatible avec l'ancien code pendant ces quelques secondes (ajouter une colonne, oui ; en renommer une, en deux déploiements).

### Essayer la production en local

Sans toucher à l'environnement de développement (nom de projet, port et volumes différents) :

```bash
# Fichier de variables de test, hors du dépôt
cat > /tmp/prod-test.env <<EOF
APP_SECRET=$(openssl rand -hex 32)
DB_PASSWORD=$(openssl rand -hex 16)
DB_ROOT_PASSWORD=$(openssl rand -hex 16)
MAILER_DSN=null://null
DEFAULT_URI=http://localhost:8090
HTTP_PORT=8090
EOF

docker compose -p ecommerce-prod-test -f compose.prod.yaml --env-file /tmp/prod-test.env up -d --build
docker compose -p ecommerce-prod-test -f compose.prod.yaml --env-file /tmp/prod-test.env exec php bin/console doctrine:migrations:migrate -n
# … http://localhost:8090 …
docker compose -p ecommerce-prod-test -f compose.prod.yaml --env-file /tmp/prod-test.env down -v   # supprime aussi les volumes de test
```
