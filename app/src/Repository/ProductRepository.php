<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\ProductVariant;
use App\Enum\CatalogSort;
use App\Model\CatalogQuery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Product>
 */
class ProductRepository extends ServiceEntityRepository
{
    public const CATALOG_PAGE_SIZE = 12;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Product::class);
    }

    /**
     * Une page du catalogue : produits actifs filtrés par recherche et catégorie, triés.
     *
     * Le Paginator exécute deux requêtes : le comptage (count($paginator)) et la page
     * elle-même (au premier parcours). La catégorie est chargée dans la même requête
     * que les produits, ce qui évite une requête par carte produit (problème N+1).
     * Elle est passée à part car le contrôleur l'a déjà chargée à partir de $query->categorySlug.
     *
     * @return Paginator<Product>
     */
    public function findCatalogPage(
        CatalogQuery $query,
        ?Category $category,
        int $pageSize = self::CATALOG_PAGE_SIZE,
    ): Paginator {
        $queryBuilder = $this->createQueryBuilder('p')
            ->addSelect('c')
            ->join('p.category', 'c')
            ->where('p.isActive = :active')
            ->setParameter('active', true)
            ->setFirstResult(($query->page - 1) * $pageSize)
            ->setMaxResults($pageSize);

        if (null !== $category) {
            $queryBuilder
                ->andWhere('p.category = :category')
                ->setParameter('category', $category);
        }

        if (null !== $query->search) {
            // Le terme est un paramètre de requête, jamais concaténé dans le SQL.
            // ESCAPE '!' : le caractère d'échappement utilisé par escapeLike().
            $queryBuilder
                ->andWhere("(p.name LIKE :search ESCAPE '!' OR p.description LIKE :search ESCAPE '!')")
                ->setParameter('search', '%'.$this->escapeLike($query->search).'%');
        }

        $this->applySort($queryBuilder, $query->sort);

        // Seule une relation « vers un » (la catégorie) est jointe : chaque ligne SQL est un produit,
        // LIMIT peut donc s'appliquer directement, sans la requête intermédiaire sur les identifiants.
        $paginator = new Paginator($queryBuilder, fetchJoinCollection: false);
        // Comptage simple « SELECT COUNT(DISTINCT p.id) ... » au lieu d'une sous-requête
        $paginator->setUseOutputWalkers(false);

        return $paginator;
    }

    /**
     * Les derniers produits actifs ajoutés au catalogue (sélection de la page d'accueil), avec leur catégorie.
     * L'identifiant départage les produits créés à la même seconde (fixtures, imports).
     *
     * @return list<Product>
     */
    public function findLatestActive(int $limit): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c')
            ->join('p.category', 'c')
            ->where('p.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('p.createdAt', 'DESC')
            ->addOrderBy('p.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Charge en une seule requête les variantes de ces produits (déjà chargés), au lieu d'une requête
     * par produit au premier appel de getVariants() (problème N+1). Doctrine remplit alors leurs collections.
     *
     * @param list<Product> $products
     */
    public function loadVariants(array $products): void
    {
        if ([] === $products) {
            return;
        }

        $this->createQueryBuilder('p')
            ->addSelect('v')
            ->leftJoin('p.variants', 'v')
            ->where('p IN (:products)')
            ->setParameter('products', $products)
            ->getQuery()
            ->getResult();
    }

    /**
     * Tous les produits, actifs ou non, avec leur catégorie et leurs variantes (pour l'administration).
     *
     * @return list<Product>
     */
    public function findAllWithCategory(): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('c', 'v')
            ->join('p.category', 'c')
            ->leftJoin('p.variants', 'v')
            ->orderBy('p.name', 'ASC')
            ->addOrderBy('v.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function isSlugTaken(string $slug): bool
    {
        return $this->count(['slug' => $slug]) > 0;
    }

    /**
     * La correspondance à appliquer selon le tri choisi. Les champs viennent de ce code,
     * jamais de l'URL. L'identifiant départage les ex aequo : sans lui, deux produits de même prix
     * pourraient changer d'ordre d'une requête à l'autre et apparaître sur deux pages.
     *
     * Le prix trié est celui affiché (« à partir de ») : le plus petit prix des variantes actives,
     * le prix d'une variante étant le sien ou, à défaut, celui du produit (ADR 0017).
     * HIDDEN : la valeur sert au tri mais n'est pas renvoyée avec les produits.
     */
    private function applySort(QueryBuilder $queryBuilder, CatalogSort $sort): void
    {
        if (in_array($sort, [CatalogSort::PriceAsc, CatalogSort::PriceDesc], true)) {
            $queryBuilder->addSelect(sprintf(
                '(SELECT MIN(COALESCE(sv.price, p.price)) FROM %s sv WHERE sv.product = p AND sv.isActive = true) AS HIDDEN fromPrice',
                ProductVariant::class,
            ));
        }

        [$field, $direction] = match ($sort) {
            CatalogSort::Name => ['p.name', 'ASC'],
            CatalogSort::PriceAsc => ['fromPrice', 'ASC'],
            CatalogSort::PriceDesc => ['fromPrice', 'DESC'],
            CatalogSort::Newest => ['p.createdAt', 'DESC'],
        };

        $queryBuilder
            ->orderBy($field, $direction)
            ->addOrderBy('p.id', $direction);
    }

    /**
     * Dans LIKE, % (n'importe quelle suite de caractères) et _ (un caractère) sont des jokers.
     * On les précède de « ! » pour qu'ils soient cherchés tels quels, et « ! » lui-même est doublé.
     * « ! » plutôt que « \ » : dans une chaîne SQL MariaDB, « '\' » échappe le guillemet et casse la requête.
     * strtr() remplace en une seule passe : il ne ré-échappe pas ce qu'il vient d'ajouter.
     */
    private function escapeLike(string $value): string
    {
        return strtr($value, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    /**
     * Verrouille la ligne du produit jusqu'à la fin de la transaction et relit ses données à jour.
     * Doit être appelée à l'intérieur d'une transaction.
     */
    public function lockForUpdate(Product $product): void
    {
        $this->getEntityManager()->refresh($product, LockMode::PESSIMISTIC_WRITE);
    }
}
