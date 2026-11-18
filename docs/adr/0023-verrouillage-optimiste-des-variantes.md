# ADR 0023 : Verrouillage optimiste des variantes dans l'administration

- **Date** : 2026-11-14
- **Statut** : accepté

## Contexte

Le formulaire d'administration d'une variante (`/admin/variantes/{id}/modifier`) contient le stock **affiché à l'ouverture** et l'enregistre tel quel (constat A-01 de `docs/audit.md`). Si un client commande pendant que l'administrateur remplit le formulaire, la vente est effacée : 12 en stock, 3 vendus pendant la saisie, l'administrateur enregistre sans rien changer, le stock repasse à 12. Le site peut alors vendre des articles qui n'existent plus.

Les verrous pessimistes de `OrderService` (`SELECT … FOR UPDATE`, ADR 0007) ne protègent pas ce cas : l'administrateur réfléchit plusieurs minutes entre l'affichage du formulaire et l'envoi, et on ne garde pas un verrou de base de données ouvert entre deux requêtes HTTP.

## Options étudiées

1. **Verrouillage optimiste** (retenue) : une colonne `version` que Doctrine incrémente à chaque modification de la variante. Le formulaire transporte la version lue à l'ouverture ; à l'enregistrement, si elle a changé, rien n'est enregistré et l'administrateur est prévenu.
2. **Saisie d'un ajustement** (+5 / −2) appliqué sous verrou pessimiste : plus de conflit possible sur le stock, mais l'écran change de nature (on ne voit plus « mettre le stock à 20 », ce qu'on fait après un inventaire), et le prix ou le libellé modifiés pendant la saisie d'un autre administrateur seraient toujours écrasés.
3. **Comparer seulement le stock** (champ caché « stock à l'ouverture ») : pas de migration, mais ne détecte que les changements de stock, et la comparaison doit se faire sous verrou pour ne pas laisser de fenêtre entre la lecture et l'écriture.

## Décision

- `ProductVariant::$version` (`#[ORM\Version]`, colonne `version`, défaut 1). Toute modification faite par Doctrine (vente, annulation, désactivation, formulaire) l'incrémente automatiquement.
- `ProductVariantData::$version` et un champ caché `version` dans `ProductVariantType`.
- `ProductVariantService::update()` compare la version reçue à celle de la variante (`EntityManager::lock(…, LockMode::OPTIMISTIC, $version)`), puis le `flush()` vérifie encore (`UPDATE … WHERE id = ? AND version = ?`) : une vente enregistrée entre les deux est aussi détectée. Dans les deux cas, Doctrine lève `OptimisticLockException`.
- Le contrôleur affiche un message d'erreur et **recharge** le formulaire avec les valeurs à jour. Il ne réaffiche pas les valeurs saisies : un second clic sur « Enregistrer » remettrait sinon l'ancien stock.

## Conséquences

- Une vente pendant la saisie oblige l'administrateur à recommencer sa modification (ses autres changements, libellé ou prix, sont perdus). C'est rare et préférable à un stock faux.
- Le verrou optimiste et les verrous pessimistes de `OrderService` cohabitent : `refresh(…, PESSIMISTIC_WRITE)` relit aussi la version, la commande n'échoue donc jamais à cause d'elle.
- Une modification faite par une requête SQL directe (sans Doctrine) doit incrémenter `version` elle-même ; il n'en existe aucune aujourd'hui.
