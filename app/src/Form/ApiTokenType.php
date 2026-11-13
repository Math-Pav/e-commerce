<?php

declare(strict_types=1);

namespace App\Form;

use App\Model\ApiTokenData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<ApiTokenData>
 */
final class ApiTokenType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('name', TextType::class, [
            'label' => 'Nom du jeton',
            'empty_data' => '',
            'help' => 'Par exemple « Mon téléphone ».',
            'attr' => ['maxlength' => 50],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ApiTokenData::class,
            'csrf_token_id' => 'account_api_token',
        ]);
    }
}
