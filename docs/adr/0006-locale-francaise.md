# ADR 0006 : Site en français et traduction des messages de Symfony

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

Nos textes (templates, messages flash, messages de validation personnalisés) sont écrits directement en français. En revanche, les messages fournis par Symfony lui-même s'affichaient en anglais : contraintes de validation sans message personnalisé (par exemple `Length(max: 50)` sur le prénom : « This value is too long… »), erreur CSRF d'un formulaire, erreurs de connexion du composant Security. La locale par défaut était `en` et le composant Translation n'était pas installé : Symfony n'avait donc aucun moyen d'utiliser ses propres fichiers de traduction français.

Le site n'a qu'une langue, et aucun site multilingue n'est prévu.

## Options étudiées

1. **Écrire un message personnalisé sur chaque contrainte** : aucune dépendance, mais il faut penser à le faire partout, et cela ne couvre ni les messages du composant Security ni l'erreur CSRF des formulaires.
2. **Installer `symfony/translation` et passer la locale par défaut à `fr`**, en gardant nos textes en dur : Symfony traduit automatiquement ses propres messages grâce aux fichiers `*.fr.xlf` livrés avec le Validator, le Form et le Security.
3. **Option 2 + extraire tous nos textes dans des fichiers `translations/messages.fr.yaml`** : prépare un site multilingue, mais ajoute une indirection (des clés à la place des phrases) dans tous les templates, sans besoin réel aujourd'hui.

## Décision

Option 2.

- Dépendance `symfony/translation` (composant officiel), configurée dans `config/packages/translation.yaml` : `default_locale: fr`, `enabled_locales: ['fr']`, langue de repli `fr`.
- `base.html.twig` utilise la locale de la requête pour l'attribut `lang` de `<html>` (`{{ app.request.locale }}`), utile aux lecteurs d'écran et aux navigateurs.
- La page de connexion affiche le message d'erreur fourni par Symfony, traduit avec le domaine `security` (`error.messageKey|trans(error.messageData, 'security')`), au lieu d'un texte fixe. Le message correspond ainsi à la vraie cause de l'échec (identifiants invalides, jeton CSRF invalide, trop de tentatives…).
- Nos propres textes restent écrits en français directement dans le code. Le dossier `translations/` reste vide.

## Conséquences

- Toute contrainte sans message personnalisé affiche le message français de Symfony. On peut donc se contenter du message par défaut quand il est assez clair.
- Une dépendance de plus, maintenue par Symfony.
- Passer un jour à un site multilingue demandera d'extraire nos textes dans des fichiers de traduction (option 3) et d'ajouter la locale dans les routes ; ce sera l'objet d'un nouvel ADR.
