<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Category;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

/**
 * Données de démonstration pour le développement.
 * Commande : bin/console doctrine:fixtures:load (ATTENTION : vide la base avant de la remplir).
 */
class AppFixtures extends Fixture
{
    /**
     * Catalogue de démonstration : catégorie => liste de produits.
     * Chaque produit : [nom, prix en centimes, stock, description, actif].
     */
    private const CATALOG = [
        'Mugs et tasses' => [
            ['Mug blanc classique', 1290, 25, 'Un mug en céramique de 33 cl, simple et indémodable.', true],
            ['Mug noir mat', 1490, 12, 'Finition mate au toucher doux, compatible lave-vaisselle.', true],
            ['Tasse à expresso', 890, 40, 'Petite tasse de 8 cl pour les amateurs de café serré.', true],
            ['Mug isotherme', 2490, 0, 'Garde vos boissons chaudes pendant 3 heures.', true],
        ],
        'Carnets' => [
            ['Carnet A5 ligné', 990, 50, '96 pages lignées, couverture rigide.', true],
            ['Carnet à spirale A4', 690, 30, '120 pages quadrillées, idéal pour les cours.', true],
            ['Carnet de croquis', 1590, 8, 'Papier épais de 160 g, adapté au crayon et à l\'aquarelle.', true],
            ['Agenda 2026', 1290, 15, 'Une semaine sur deux pages, édition limitée.', false],
        ],
        'Stylos' => [
            ['Stylo à bille bleu', 150, 200, 'Le classique indispensable.', true],
            ['Stylo plume', 3490, 5, 'Plume en acier inoxydable, livré avec deux cartouches.', true],
            ['Lot de 10 surligneurs', 790, 60, 'Dix couleurs vives pour organiser vos notes.', true],
        ],
    ];

    // Mot de passe de tous les comptes de démonstration
    private const DEMO_PASSWORD = 'password';

    public function __construct(
        private readonly SluggerInterface $slugger,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
    }

    public function load(ObjectManager $manager): void
    {
        $manager->persist($this->createUser('client@example.com', 'Camille', 'Martin'));
        $manager->persist($this->createUser('admin@example.com', 'Alex', 'Durand', ['ROLE_ADMIN']));

        foreach (self::CATALOG as $categoryName => $products) {
            $category = (new Category())
                ->setName($categoryName)
                ->setSlug($this->slugify($categoryName));

            $manager->persist($category);

            foreach ($products as [$name, $price, $stock, $description, $isActive]) {
                $product = (new Product())
                    ->setName($name)
                    ->setSlug($this->slugify($name))
                    ->setPrice($price)
                    ->setStock($stock)
                    ->setDescription($description)
                    ->setIsActive($isActive)
                    ->setCategory($category);

                $manager->persist($product);
            }
        }

        $manager->flush();
    }

    /**
     * @param list<string> $roles
     */
    private function createUser(string $email, string $firstName, string $lastName, array $roles = []): User
    {
        $user = (new User())
            ->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setRoles($roles);

        $user->setPassword($this->passwordHasher->hashPassword($user, self::DEMO_PASSWORD));

        return $user;
    }

    private function slugify(string $text): string
    {
        return $this->slugger->slug($text)->lower()->toString();
    }
}
