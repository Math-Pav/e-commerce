# Charte graphique

Référence de l'interface du site : jetons de design, composants, règles de rédaction et de mise en page mobile.
La feuille de style est `app/assets/styles/app.css` ; les fragments Twig communs sont dans `app/templates/components/`.

## Identité : « papeterie d'atelier »

Papier crème, encre presque noire, **vert sapin** pour les actions et **terre cuite** pour les mises en avant ; titres en serif « de livre », texte dans la police du système. Choix et raisons : `docs/adr/0025-direction-visuelle.md`.

- **Aucune ressource externe** : ni police, ni image, ni script venant d'un autre domaine (future CSP stricte). Polices système uniquement ; illustrations et visuels de remplacement en SVG et en CSS.
- **Contrastes AA** vérifiés pour chaque couple texte/fond des jetons ; contour des champs à 3:1 au moins.
- **Mouvement sobre** : transitions de `--transition` (160 ms), aucune sous `prefers-reduced-motion: reduce`.

## Principes

- **Un composant par besoin** : avant d'écrire du CSS, chercher une classe existante ci-dessous.
- **Jamais de valeur en dur** dans les règles : couleurs, tailles de texte, rayons, ombres et zones cliquables passent par les jetons (`var(--…)`).
- **Aucun style en ligne** (`style="…"`) dans les pages : il bloquerait une future CSP (audit A-05).
- Classes nommées en **BEM** : `bloc`, `bloc__element`, `bloc--variante`.
- Nouveau CSS : à la fin de la section concernée, avec un commentaire qui dit à quoi il sert.

Exceptions assumées : les **e-mails** (`templates/emails/`) et la **facture PDF** (`templates/invoice/pdf.html.twig`) écrivent leurs styles en ligne avec les couleurs hexadécimales des jetons. Les messageries et dompdf ne lisent ni la feuille de style ni les variables CSS.

## Jetons de design (`:root`)

| Famille | Jetons | Usage |
| --- | --- | --- |
| Couleurs de base | `--color-bg` (crème), `--color-surface` (kraft), `--color-surface-raised` (blanc), `--color-text`, `--color-text-soft`, `--color-muted`, `--color-text-inverse` | Fond, panneaux, cartes ; texte principal, secondaire, discret ; texte sur fond coloré |
| Bordures | `--color-border`, `--color-border-strong`, `--color-field-border` | Séparateurs ; contours décoratifs ; contour des champs (3:1) |
| Accent | `--color-accent` (vert sapin), `--color-accent-hover`, `--color-accent-soft` | Liens, bouton principal, élément actif ; fond d'une option choisie |
| Mise en avant | `--color-highlight` (terre cuite), `--color-highlight-soft`, `--color-focus` | Surtitres, numéro des pages d'erreur ; anneau de focus |
| Sections foncées | `--color-dark`, `--color-ink`, `--color-on-dark`, `--color-on-dark-soft`, `--color-on-dark-accent`, `--color-on-ink-soft`, `--color-on-dark-line` | Bandeau d'accueil, colonne des pages de connexion, pied de page. Ces sections redéfinissent `--color-focus` (ocre) pour garder un focus visible |
| États | `--color-success(-bg)`, `--color-warning(-bg)`, `--color-danger(-bg)` | Badges, messages, stock, actions définitives |
| Teintes catégories | `--tint-mugs`, `--tint-carnets`, `--tint-stylos`, `--tint-default` et leur `-ink` | Fond et dessin des visuels de remplacement |
| Polices | `--font-body` (police d'interface du système), `--font-display` (serif système : Iowan, Palatino, P052, Georgia) | Texte courant ; titres, prix, noms de produits |
| Tailles de texte | `--text-2xs` (12) … `--text-base` (16) … `--text-8xl` (60) | Toujours une de ces tailles |
| Rayons | `--radius-xs` (6), `--radius-sm` (8), `--radius-md` (10), `--radius-lg` (14), `--radius-xl` (20), `--radius-pill` | Champs ; cartes ; bandeaux ; boutons et pastilles (pilule) |
| Ombres | `--shadow-sm`, `--shadow-raised` | Léger relief ; carte survolée, menu déroulant |
| Mouvement | `--transition` (160 ms) | Survol, ouverture du menu |
| Mise en page | `--container` (1200), `--gutter` (32, 24 sous 900 px, 16 sous 640 px) | Largeur maximale, marge latérale |
| Accessibilité | `--tap-target` (44 px) | Hauteur minimale d'un lien isolé ou d'un bouton |

## Fragments Twig (`templates/components/`)

| Fragment | Paramètres | Quand l'utiliser |
| --- | --- | --- |
| `_page_header.html.twig` | `title` ; facultatifs : `back` `{url, label}`, `eyebrow`, `lead`, `title_modifier: 'xl'` ; blocs `lead` et `actions` (avec `embed`) | En haut de **chaque** page. Le lien de retour est toujours au-dessus du titre ; un bouton « Nouveau… » ou un badge de statut va dans le bloc `actions`. |
| `_empty_state.html.twig` | `message` ; facultatif : `action` `{url, label}` | Liste vide, recherche sans résultat, page d'erreur. L'action est le bouton qui permet d'en sortir. |
| `_table.html.twig` | `columns` (`{label, num, hidden}`), `caption` ; blocs `body` et `foot` (avec `embed`) | Tout tableau de données. `num` aligne à droite (montants, quantités) ; `hidden` pour la colonne « Actions ». |
| `_pagination.html.twig` | `pagination`, `route`, `route_params` (sans la page), `label` | Toute liste découpée en pages (aujourd'hui le catalogue, via `product/_pagination.html.twig`). |

| `_logo.html.twig` | aucun | Logo (en-tête et pied de page). |
| `_checkout_steps.html.twig` | `current` (1 panier, 2 livraison, 3 paiement) | En haut du panier rempli, du tunnel et d'une commande à payer. |

Autres fragments partagés : `product/_card.html.twig` (carte produit du catalogue et de l'accueil), `cart/_assurance.html.twig` (rappels sous un récapitulatif), `security/_auth_aside.html.twig` (avantages du compte), `order/_status_badge.html.twig` (statut d'une commande), `order/_items_table.html.twig` (lignes et totaux d'une commande, client et administration), `product/_image.html.twig` (photo ou visuel), `cart/_discount.html.twig` (remise), `bundles/TwigBundle/Exception/_error_page.html.twig` (pages d'erreur).

## Composants CSS

### Mise en page
- `.container` : largeur maximale et marge latérale. `.layout-split` (`__main`, `__aside`) : contenu et colonne latérale, qui passe dessous sur mobile.
- `.panel` : bloc sur fond gris (récapitulatif, formulaire secondaire). `.callout` : encadré avec un message et une ou deux actions.
- `.toolbar` : ligne qui s'enroule (filtres + compteur `.toolbar__count`, ou compteur + bouton).

### Accueil et mises en avant
- `.hero` (`__content`, `__title`, `__lead`, `__actions`, `__art`, `__tile`) : bandeau vert de l'accueil, un seul `h1`. Boutons `.btn--light` et `.btn--ghost-light` uniquement sur fond foncé.
- `.section-heading` (`__intro`, `__link`) : surtitre, titre et lien « tout voir ». `.category-grid` / `.category-card` : univers de l'accueil.
- `.assurance` (`__item`, `__icon`, `__title`, `__text`), variantes `--band` (accueil) et `--compact` (fiche, récapitulatifs). **Règle** : n'écrire que ce que le site fait (livraison en France, paiement simulé, annulation avant paiement, facture PDF) ; jamais « paiement sécurisé », « retours gratuits » ou « livraison offerte ».

### Produits
- `.product-grid` / `.product-card` (`__visual`, `__badge`, `__category`, `__name`, `__footer`, `__price`, `__from`, `__stock`) : via `product/_card.html.twig`.
- `.product-visual` : photo (`--photo`) ou dessin de remplacement sur la teinte de la catégorie (`--mugs-et-tasses`, `--carnets`, `--stylos`), motif pointillé en CSS ; tailles `--lg`, `--thumb`.
- Fiche : `.product-detail` (`__media` collant sur grand écran, `__info`, `__price-row`), `.variant-choice` (une carte par variante, option choisie sur fond `--color-accent-soft`), `.add-to-cart` (`__row`, `__submit`).

### Tunnel de commande
- `.steps` via `_checkout_steps.html.twig` ; `.cart-lines` / `.cart-line` (une carte par ligne) ; `.summary-panel` (récapitulatif collant sur grand écran) ; `.form-card` (formulaire d'une étape) ; `.callout--action` (payer ou annuler).

### Compte et erreurs
- `.auth-layout`, `.auth-card`, `.auth-aside` : connexion et inscription. `.error-page` (`__code`, `__actions`) : pages 403, 404, 500.

### Titres et textes
- `.eyebrow` (surtitre en terre cuite, précédé d'un trait), `.page-title` (h1, `--xl` pour le catalogue), `.lead` (introduction), `.section-title` (h2), `.note` (précision discrète), `.back-link` (retour, toujours préfixé « ← »).

### Boutons (`.btn` + une variante)
| Variante | Usage | Règle |
| --- | --- | --- |
| `.btn--primary` | L'action principale d'un formulaire ou d'une page | Une seule par formulaire |
| `.btn--secondary` | Actions secondaires, actions de ligne de tableau | |
| `.btn--danger` | Action définitive : annuler une commande, supprimer, révoquer | Toujours accompagnée d'un texte qui dit qu'elle est définitive, ou d'un message qui explique un refus |
| `.btn--lg`, `.btn--block` | Taille (étape importante : payer, valider) ; pleine largeur (panneau, carte de connexion) | Se combinent avec une variante |

Un lien qui mène à une autre page reste un `<a href>` ; une action qui modifie des données est un `<button>` dans un `<form method="post">` avec jeton CSRF.

### Badges et états
- `.badge--success` (payé, actif, en stock), `--warning` (en attente), `--danger` (annulé, rupture, expiré), `--neutral` (désactivé, information).
- `.stock`, `.stock--low`, `.stock--out` : stock dans les tableaux d'administration.
- `.flash--success` / `.flash--error` : messages après une action (rôle `status` ou `alert`, gérés par `base.html.twig`).

### Navigation
- `.site-nav` et `.account-menu` (menu du compte en `<details>`, sans JavaScript), `.cart-link`.
- Visiteur non connecté : « Se connecter » (lien) puis « Créer un compte » (`.btn.btn--secondary.site-nav__register`, bouton discret : l'inscription est secondaire par rapport au catalogue). Ce bouton est masqué sous 640 px ; sur mobile, l'inscription se fait par le bouton pleine largeur de la page de connexion.
- Bandeau `.announcement` au-dessus de l'en-tête (rappel : boutique de démonstration, paiement simulé) ; lien d'évitement `.skip-link` vers `#contenu`.
- Pied de page `.site-footer` : colonnes de liens **existants uniquement** (`__title`, `__list`, `__link`, `__facts`).
- `.tabs` : onglets de l'administration, en barre segmentée qui défile dans son cadre sur petit écran ; `.chips` / `.chip` : filtres ; `.breadcrumb` : fil d'Ariane ; `.pagination`.
- L'élément actif porte `aria-current="page"` (le style en dépend).

### Formulaires
- `.form` sur `form_start()` (champs Symfony) ou `.field` (champ écrit à la main : `<label>` + `.input`). `.form__inline` : deux champs côte à côte, l'un sous l'autre sur mobile.
- Tout champ a un `<label for>` visible. Les erreurs de Symfony s'affichent sous le champ.

### Données
- Tableaux : via `_table.html.twig` ; ils défilent dans leur cadre sur petit écran, la page ne défile jamais horizontalement.
- `.summary-list` / `.summary-row` / `.summary-total` : récapitulatifs de montants. `.stat-grid` / `.stat` : chiffres clés (carte blanche, liseré vert). `.admin-form` : page de création ou de modification, formulaire en carte. `.bar-chart` : graphique en SVG, doublé d'un tableau.

## Règles de rédaction

- **Ton** : chaleureux et simple, jamais racoleur ; aucune promesse que le site ne tient pas (voir `.assurance`).
- **Vouvoiement**, phrases courtes, français correct (accords : « 1 produit », « 2 produits »).
- **Un libellé par action**, partout :
  | Action | Libellé |
  | --- | --- |
  | Ouvrir le formulaire de création | « Nouveau produit », « Nouvelle catégorie »… (même texte dans l'état vide) |
  | Valider une création | « Créer le produit », « Créer la variante »… |
  | Ouvrir le formulaire de modification | « Modifier » |
  | Valider une modification | « Enregistrer les modifications » |
  | Enlever un élément d'un ensemble (panier, code promo, photo) | « Retirer » |
  | Détruire définitivement | « Supprimer » |
  | Rendre visible / invisible aux clients | « Activer » / « Désactiver » ; statut « Actif(ve) » / « Désactivé(e) » |
  | Retour à la page parente | « ← Retour à… » (« ← Retour aux commandes », « ← Retour à mon compte ») |
- **Un bouton décrit son action** par un verbe : « Appliquer le code », « Payer la commande · 24,80 € ». Quand le libellé seul est ambigu (une ligne de tableau), compléter avec un texte `.visually-hidden` : « Modifier<span class="visually-hidden"> Mug noir mat</span> ».
- **Un message d'erreur dit ce qui s'est passé et quoi faire** : « Le code promo « ETE » n'existe pas. Vérifiez son orthographe. »
- **Un message de succès nomme l'élément** : « Le produit « Mug noir mat » a été créé. »
- Dates : `d/m/Y` ou `d/m/Y à H:i`. Montants : filtre `price` (« 12,90 € »).

## Mobile (375 px) et accessibilité

- Aucune page ne défile horizontalement ; seuls les tableaux et le graphique défilent dans leur cadre.
- Toute zone cliquable isolée mesure au moins `--tap-target` (44 px) de haut ; les liens à l'intérieur d'une phrase font exception.
- Sous 640 px : bandeau d'accueil resserré (boutons pleine largeur), photo de la fiche et récapitulatifs non collants, bouton d'achat pleine largeur, marge latérale de 16 px, en-tête sur deux lignes (logo, puis navigation), libellé « Panier » masqué visuellement (lu par les lecteurs d'écran), bouton « Créer un compte » retiré de l'en-tête (`display: none`, sans JavaScript) pour que la navigation tienne sur une seule ligne à 375 px, boutons des encadrés en pleine largeur.
- Les textes sans point de coupure (références de commande, adresses e-mail, `<code>`) sont coupés avec `overflow-wrap: anywhere`.
- Focus visible sur tout élément interactif (`:focus-visible`) ; ne jamais le supprimer.
