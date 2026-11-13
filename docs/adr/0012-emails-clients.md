# ADR 0012 : E-mails envoyés aux clients

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

Le site n'envoyait aucun e-mail. Le client doit être prévenu aux moments clés de son parcours : création du compte, commande enregistrée (avec le récapitulatif), paiement accepté, commande annulée (par lui ou par l'administrateur).

Contraintes :

- en développement, aucun e-mail ne doit partir chez un vrai destinataire ;
- les tests ne doivent dépendre d'aucun serveur externe ;
- un e-mail qui ne part pas ne doit jamais faire échouer l'inscription, la commande, le paiement ou l'annulation.

Cette branche part de `feature/customer-cancel-order`, la seule où l'annulation (par l'administrateur et par le client) existe.

(Les numéros 0006 à 0011 sont réservés aux ADR d'autres branches non fusionnées.)

## Options étudiées

### Bibliothèque d'envoi

1. **`mail()` de PHP** : aucune dépendance, mais pas de SMTP configurable, pas de templates, pas de tests possibles sans envoi réel.
2. **PHPMailer** : bibliothèque tierce répandue, mais sans intégration Symfony (configuration, Twig, assertions de test).
3. **Le composant officiel `symfony/mailer`** : transport choisi par une simple variable (`MAILER_DSN`), e-mails Twig (`TemplatedEmail`), assertions de test intégrées (`assertEmailCount()`…). Dépendance ajoutée avec l'accord du développeur.

### Boîte de réception de développement

1. **Transport `null://null` partout** : rien n'est envoyé, mais on ne voit jamais le rendu.
2. **Mailpit** (service Docker) : faux serveur SMTP qui garde les e-mails et les affiche dans une interface web. Le rendu réel est visible, aucun e-mail ne sort.

### Où déclencher l'envoi

1. **Dans les contrôleurs**, après l'appel au service : mais l'annulation est déclenchée par deux contrôleurs (client et administration), et une future commande console devrait penser à l'envoi elle aussi.
2. **Dans les services métier** (`RegistrationService`, `OrderService`, `PaymentService`), après l'enregistrement : chaque appelant, présent ou futur, envoie l'e-mail sans y penser.
3. **Des événements Symfony** (`OrderPlacedEvent`…) écoutés par un abonné qui envoie l'e-mail : découplage maximal, mais quatre classes d'événement et un abonné pour un seul usage aujourd'hui. Ce serait un mécanisme ajouté sans besoin concret.

## Décision

- `symfony/mailer` ; `MAILER_DSN=smtp://mailpit:1025` dans `.env` (développement) et `MAILER_DSN=null://null` dans `.env.test`. Un service `mailpit` (image `axllent/mailpit`) est ajouté à `compose.yaml` ; son interface web est sur http://localhost:8025.
- Un service unique, `App\Service\CustomerMailer`, avec une méthode par e-mail : `sendWelcome()`, `sendOrderConfirmation()`, `sendPaymentConfirmation()`, `sendOrderCancellation()`. Il construit un `TemplatedEmail` (version HTML **et** version texte) à partir des templates de `templates/emails/`.
- L'expéditeur est défini une seule fois, dans les paramètres `app.mailer.sender_email` et `app.mailer.sender_name` de `config/services.yaml`, injectés avec `#[Autowire]`.
- L'envoi est appelé **par les services métier**, **après** l'enregistrement en base (après `flush()` ou après la transaction de `wrapInTransaction()`). Une transaction qui échoue n'envoie donc aucun e-mail annonçant une commande qui n'existe pas.
- `CustomerMailer` attrape les erreurs de transport (`TransportExceptionInterface`) et les **journalise** (niveau `error`, avec l'identifiant du client et non son adresse e-mail) au lieu de les propager.
- Templates : une mise en page commune (`layout.html.twig`) en styles en ligne et tableaux, aux couleurs de `app.css`, et des fragments partagés (`_order_summary`, `_button`, `_footer`). Les montants passent par le filtre `price` ; les liens sont générés avec `url()` (adresse absolue), jamais `path()`.

## Conséquences

- **Envoi synchrone** : l'e-mail part pendant la requête du client, qui attend la réponse du serveur SMTP. C'est acceptable en local avec Mailpit. L'envoi asynchrone (Symfony Messenger) est hors périmètre ; le jour où il sera ajouté, il suffira de router `SendEmailMessage` vers un transport Messenger. Il faudra alors ne plus passer d'entités Doctrine dans le contexte des templates, car le message est sérialisé.
- **Liens absolus** : pendant une requête HTTP, `url()` utilise l'hôte et le port de la requête (`http://localhost:8080/...`). Hors requête (console, tâche planifiée), le routeur utilise `DEFAULT_URI` (`framework.router.default_uri`), aujourd'hui `http://localhost` dans `.env`, sans le port 8080 : il faudra le corriger (par exemple dans `.env.local`) avant d'envoyer des e-mails depuis une commande console.
- **Seules les erreurs de transport sont absorbées.** Une erreur dans un template (variable inconnue) reste une exception : c'est un bug, qui doit être vu. Les tests rendent chacun des quatre e-mails avec `strict_variables` activé, ce qui détecte ces erreurs avant la mise en ligne.
- Un serveur SMTP éteint ou injoignable n'empêche donc ni l'inscription ni la commande ; l'erreur apparaît dans les journaux (`docker compose logs php`).
- Aucun e-mail n'est envoyé à l'administrateur (hors périmètre).
