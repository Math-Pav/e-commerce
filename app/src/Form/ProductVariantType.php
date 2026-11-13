<?php

declare(strict_types=1);

namespace App\Form;

use App\Model\ProductVariantData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'administration d'une variante de produit, lié au DTO ProductVariantData.
 *
 * @extends AbstractType<ProductVariantData>
 */
final class ProductVariantType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Libellé',
                'required' => false,
                'help' => 'Par exemple « Bleu » ou « A5 ». Obligatoire dès que le produit a plusieurs variantes.',
            ])
            ->add('sku', TextType::class, [
                'label' => 'Référence (SKU)',
                'empty_data' => '',
                'help' => 'Unique. Lettres sans accent, chiffres, - et _.',
            ])
            // Même saisie que le prix d'un produit : des euros, enregistrés en centimes
            ->add('price', MoneyType::class, [
                'label' => 'Prix (€)',
                'required' => false,
                'currency' => false,
                'divisor' => 100,
                'input' => 'integer',
                'html5' => true,
                'attr' => ['min' => 0, 'step' => '0.01'],
                'help' => 'Facultatif : vide = prix du produit.',
                'invalid_message' => 'Veuillez saisir un prix valide, par exemple 12,90.',
            ])
            ->add('stock', IntegerType::class, [
                'label' => 'Stock',
                'empty_data' => '0',
                'attr' => ['min' => 0],
                'invalid_message' => 'Veuillez saisir un nombre entier.',
            ])
            ->add('isActive', CheckboxType::class, [
                'label' => 'Active (proposée aux clients)',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProductVariantData::class,
        ]);
    }
}
