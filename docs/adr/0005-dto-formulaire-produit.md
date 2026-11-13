# ADR 0005 : Formulaire d'administration des produits lié à un DTO

- **Date** : 2026-11-13
- **Statut** : accepté

## Contexte

Le formulaire d'administration d'un produit était lié directement à l'entité `Product`. Symfony écrit les valeurs saisies dans l'objet **avant** de le valider : un prix ou une catégorie vidé passe donc par `setPrice(null)` ou `setCategory(null)`. Pour que l'erreur de validation s'affiche au lieu d'une `TypeError`, ces setters acceptaient `null`, ce qui permettait de mettre un `Product` dans un état invalide (alors que les colonnes `price` et `category_id` sont obligatoires en base).

Autre effet de bord : une saisie invalide modifiait quand même l'entité en mémoire (par exemple le nom affiché dans le titre de la page en erreur).

## Options étudiées

1. **Setters nullables + contraintes sur l'entité** (situation de départ) : simple, mais l'entité peut être invalide.
2. **DTO dédié au formulaire** (`ProductData`), comme `ShippingAddress` pour la commande : le formulaire remplit le DTO, la validation porte sur le DTO, et `ProductService` ne reporte sur l'entité que des données valides.
   - Un fichier de plus et une copie des champs (DTO → entité, entité → DTO pour l'édition).
3. **Data mapper de formulaire passant par le constructeur de l'entité** : l'entité est toujours valide, mais le mécanisme (`DataMapperInterface`, `empty_data` en closure) est nettement plus complexe à comprendre et à maintenir.

## Décision

Option 2.

- `App\Model\ProductData` : propriétés publiques, contraintes de validation (`NotBlank`, `NotNull`, `PositiveOrZero`, `Length`). Le prix et la catégorie y sont nullables, car un champ laissé vide doit pouvoir y être écrit avant la validation.
- `ProductType` a pour `data_class` `ProductData`. Le prix utilise `MoneyType` (`divisor: 100`, `input: integer`) : l'administrateur saisit des euros, le DTO reçoit des centimes.
- `ProductService::create(ProductData): Product` et `ProductService::update(Product, ProductData)` copient les données validées dans l'entité. Ils refusent un DTO sans prix ni catégorie (`InvalidArgumentException`) : ce cas signale une erreur de programmation (le DTO n'a pas été validé).
- `ProductData::fromProduct()` pré-remplit le formulaire de modification.
- `Product::setPrice(int)` et `Product::setCategory(Category)` n'acceptent plus `null`. Les contraintes de validation sont retirées de l'entité : elles sont sur le DTO, seul point d'entrée des saisies utilisateur.

## Conséquences

- Une saisie invalide ne touche jamais l'entité.
- Ajouter un champ au produit demande de le reporter à trois endroits : l'entité, le DTO et la copie dans `ProductService` (`applyData()`), plus `fromProduct()`.
- Il reste une limite : un `Product` tout juste construit (`new Product()`) n'a encore ni nom, ni prix, ni catégorie (getters nullables). Imposer ces valeurs dans le constructeur garantirait une entité valide dès sa création, mais demanderait d'adapter les fixtures et les tests. C'est une piste pour plus tard.
