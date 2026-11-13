<?php

declare(strict_types=1);

namespace App\Form;

use App\Model\ProfileData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TelType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ProfileType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Les champs facultatifs n'ont pas d'empty_data : laissés vides, ils valent null (colonne vide en base)
        $builder
            ->add('firstName', TextType::class, [
                'label' => 'Prénom',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'given-name'],
            ])
            ->add('lastName', TextType::class, [
                'label' => 'Nom',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'family-name'],
            ])
            ->add('phone', TelType::class, [
                'label' => 'Téléphone (facultatif)',
                'required' => false,
                'attr' => ['autocomplete' => 'tel'],
            ])
            ->add('address', TextType::class, [
                'label' => 'Adresse (facultatif)',
                'required' => false,
                'help' => 'Elle préremplira l\'adresse de livraison de vos prochaines commandes.',
                'attr' => ['autocomplete' => 'street-address'],
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'Code postal (facultatif)',
                'required' => false,
                'help' => '5 chiffres, livraison en France uniquement.',
                'attr' => ['autocomplete' => 'postal-code', 'inputmode' => 'numeric'],
            ])
            ->add('city', TextType::class, [
                'label' => 'Ville (facultatif)',
                'required' => false,
                'attr' => ['autocomplete' => 'address-level2'],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ProfileData::class,
            // Jeton CSRF propre à ce formulaire : il ne peut pas servir à envoyer celui du mot de passe
            'csrf_token_id' => 'account_profile',
        ]);
    }
}
