<?php

declare(strict_types=1);

namespace App\Form;

use App\Entity\Category;
use App\Entity\Product;
use App\Repository\CategoryRepository;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'administration d'un produit. Le slug n'y figure pas : il est généré par ProductService.
 */
final class ProductType extends AbstractType
{
    public function __construct(private readonly CategoryRepository $categoryRepository)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('name', TextType::class, [
                'label' => 'Nom',
                'empty_data' => '',
            ])
            ->add('category', EntityType::class, [
                'label' => 'Catégorie',
                'class' => Category::class,
                'choices' => $this->categoryRepository->findBy([], ['name' => 'ASC']),
                'choice_label' => 'name',
                'placeholder' => 'Choisir une catégorie',
            ])
            // L'administrateur saisit des euros (12,90) ; divisor + input « integer » stockent des centimes (1290).
            // html5 : champ <input type="number">, envoyé au format « 12.90 » quelle que soit la langue du navigateur.
            ->add('price', MoneyType::class, [
                'label' => 'Prix (€)',
                // Pas de symbole « € » collé au champ : il est déjà dans le libellé
                'currency' => false,
                'divisor' => 100,
                'input' => 'integer',
                'html5' => true,
                'attr' => ['min' => 0, 'step' => '0.01'],
                'help' => 'En euros, par exemple 12,90.',
                'invalid_message' => 'Veuillez saisir un prix valide, par exemple 12,90.',
            ])
            ->add('stock', IntegerType::class, [
                'label' => 'Stock',
                'empty_data' => '0',
                'attr' => ['min' => 0],
                'invalid_message' => 'Veuillez saisir un nombre entier.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'attr' => ['rows' => 5],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Product::class,
        ]);
    }
}
