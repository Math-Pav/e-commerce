<?php

declare(strict_types=1);

namespace App\Form;

use App\Enum\DiscountType;
use App\Model\PromoCodeData;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\MoneyType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Formulaire d'administration d'un code promo, lié au DTO PromoCodeData.
 *
 * @extends AbstractType<PromoCodeData>
 */
final class PromoCodeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('code', TextType::class, [
                'label' => 'Code',
                'empty_data' => '',
                'help' => 'Enregistré en majuscules. Lettres sans accent, chiffres, - et _.',
            ])
            ->add('discountType', EnumType::class, [
                'label' => 'Type de remise',
                'class' => DiscountType::class,
                'choice_label' => static fn (DiscountType $type): string => $type->label(),
            ])
            ->add('percentage', IntegerType::class, [
                'label' => 'Pourcentage (%)',
                'required' => false,
                'attr' => ['min' => 1, 'max' => 100],
                'help' => 'Pour une remise en pourcentage : de 1 à 100.',
                'invalid_message' => 'Veuillez saisir un nombre entier.',
            ])
            // Même saisie que le prix d'un produit : des euros, enregistrés en centimes
            ->add('amount', MoneyType::class, $this->moneyOptions('Montant de la remise (€)', 'Pour une remise d\'un montant fixe, par exemple 5,00.'))
            ->add('startsAt', DateTimeType::class, [
                'label' => 'Début de validité',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Facultatif.',
            ])
            ->add('endsAt', DateTimeType::class, [
                'label' => 'Fin de validité',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'help' => 'Facultatif.',
            ])
            ->add('maxUsages', IntegerType::class, [
                'label' => "Nombre maximal d'utilisations",
                'required' => false,
                'attr' => ['min' => 1],
                'help' => 'Facultatif : vide = illimité.',
                'invalid_message' => 'Veuillez saisir un nombre entier.',
            ])
            ->add('minimumAmount', MoneyType::class, $this->moneyOptions('Montant minimal du panier (€)', 'Facultatif : vide = aucun minimum.'))
            ->add('isActive', CheckboxType::class, [
                'label' => 'Actif',
                'required' => false,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PromoCodeData::class,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function moneyOptions(string $label, string $help): array
    {
        return [
            'label' => $label,
            'required' => false,
            'currency' => false,
            'divisor' => 100,
            'input' => 'integer',
            'html5' => true,
            'attr' => ['min' => 0, 'step' => '0.01'],
            'help' => $help,
            'invalid_message' => 'Veuillez saisir un montant valide, par exemple 5,00.',
        ];
    }
}
