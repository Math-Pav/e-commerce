<?php

declare(strict_types=1);

namespace App\Form;

use App\Model\CategoryData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'administration d'une catégorie, lié au DTO CategoryData.
 * Le slug n'y figure pas : il est généré par CategoryService.
 *
 * @extends AbstractType<CategoryData>
 */
final class CategoryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nom',
            'empty_data' => '',
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CategoryData::class,
        ]);
    }
}
