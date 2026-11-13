<?php

declare(strict_types=1);

namespace App\Form;

use App\Model\ShippingAddress;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

final class ShippingAddressType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('fullName', TextType::class, [
                'label' => 'Nom du destinataire',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'name'],
            ])
            ->add('address', TextType::class, [
                'label' => 'Adresse',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'street-address'],
            ])
            ->add('postalCode', TextType::class, [
                'label' => 'Code postal',
                'empty_data' => '',
                'help' => '5 chiffres, livraison en France uniquement.',
                'attr' => ['autocomplete' => 'postal-code', 'inputmode' => 'numeric'],
            ])
            ->add('city', TextType::class, [
                'label' => 'Ville',
                'empty_data' => '',
                'attr' => ['autocomplete' => 'address-level2'],
            ])
            ->add('saveToProfile', CheckboxType::class, [
                'label' => 'Enregistrer cette adresse dans mon profil',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ShippingAddress::class,
        ]);
    }
}