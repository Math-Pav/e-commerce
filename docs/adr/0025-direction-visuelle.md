# ADR 0025 : Direction visuelle « papeterie d'atelier » et polices système

- **Date** : 2026-11-18
- **Statut** : accepté

## Contexte

L'harmonisation précédente avait rendu l'interface cohérente (jetons, fragments Twig), mais l'identité restait neutre : fond blanc, bleu générique, polices Inter et Space Grotesk chargées depuis Google Fonts. L'accueil était le catalogue lui-même. Le site vend des mugs, des carnets et des stylos : on veut une boutique qui donne envie d'acheter, sans dépendance nouvelle et compatible avec une future CSP stricte (audit A-05 : pas de style en ligne, pas de ressource externe).

## Options étudiées

1. **Papeterie d'atelier** (retenue) : papier crème, encre presque noire, vert sapin pour les actions, terre cuite pour les mises en avant ; titres en serif « de livre ». L'univers évoque le papier, le carnet et la tasse posée sur le bureau.
2. **Minimalisme blanc et noir** : très sobre, mais froid et proche de l'ancienne interface ; peu de caractère pour une boutique de papeterie.
3. **Couleurs vives et ludiques** : attractif pour des surligneurs, moins pour des stylos plume et des carnets ; contrastes AA plus difficiles à tenir.

Pour les polices :

1. **Polices système** (retenue) : un serif système pour les titres (`Iowan Old Style`, `Palatino Linotype`, `Book Antiqua`, `P052`, puis `Georgia`) et la police d'interface du système pour le texte (`system-ui`, `Segoe UI`, `Roboto`…).
2. **Polices libres hébergées dans le projet** (fichiers `.woff2` servis par AssetMapper) : rendu identique partout, mais il faut télécharger, vérifier la licence et maintenir des fichiers binaires, et chaque page charge 100 à 200 Ko de plus.
3. **Google Fonts** (situation précédente) : écartée. Chaque visite envoie l'adresse IP du visiteur à un tiers (question RGPD), la page dépend d'un service externe et une CSP stricte devrait l'autoriser explicitement.

## Décision

- Jetons de couleur dans `:root` (`app/assets/styles/app.css`), tous vérifiés au contraste AA : texte courant 16:1, texte discret 5,6:1 sur le fond crème, bouton principal 10:1, contour des champs 3,4:1 (WCAG 1.4.11).
- Polices système uniquement ; aucun `<link>` ni `@import` vers un autre domaine. Les visuels de remplacement et les illustrations sont dessinés en SVG et en CSS.
- Micro-transitions de 160 ms, supprimées par `prefers-reduced-motion: reduce`.
- Une vraie page d'accueil (`ProductController::home()`) : bandeau, univers, quatre nouveautés (`ProductRepository::findLatestActive()`), réassurance. Les arguments ne promettent que ce que le site fait (livraison en France, paiement simulé, annulation avant paiement, facture PDF).
- Les e-mails et la facture PDF gardent leurs styles en ligne, avec les mêmes couleurs hexadécimales.

## Conséquences

- Le rendu des titres varie un peu selon le système (Palatino sur Windows, Iowan sur macOS et iOS, P052 sous Linux) ; la mise en page n'en dépend pas.
- Aucune requête vers un tiers : la page s'affiche même hors connexion à Internet, et la CSP pourra se limiter à `'self'`.
- Changer de palette se fait dans les jetons ; les règles n'utilisent jamais de couleur en dur (sauf e-mails et PDF, voir `docs/charte-graphique.md`).
