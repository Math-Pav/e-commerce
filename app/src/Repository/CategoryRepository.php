<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Category;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Toutes les catégories avec leur nombre de produits (actifs ou non), en une seule requête :
     * LEFT JOIN pour garder les catégories vides, COUNT + GROUP BY pour compter par catégorie.
     *
     * @return list<array{category: Category, productCount: int}>
     */
    public function findAllWithProductCount(): array
    {
        $rows = $this->createQueryBuilder('c')
            ->select('c AS category', 'COUNT(p.id) AS productCount')
            // Category ne connaît pas ses produits (relation déclarée côté Product) : jointure explicite sur l'entité
            ->leftJoin(Product::class, 'p', 'WITH', 'p.category = c')
            ->groupBy('c.id')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        return array_map(
            static fn (array $row): array => ['category' => $row['category'], 'productCount' => (int) $row['productCount']],
            $rows,
        );
    }

    /**
     * Catégories ayant au moins un produit actif (filtres du catalogue public).
     *
     * @return list<Category>
     */
    public function findWithActiveProducts(): array
    {
        return $this->createQueryBuilder('c')
            ->distinct()
            ->join(Product::class, 'p', 'WITH', 'p.category = c AND p.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function isSlugTaken(string $slug): bool
    {
        return $this->count(['slug' => $slug]) > 0;
    }
}
