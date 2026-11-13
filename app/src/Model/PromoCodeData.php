<?php

declare(strict_types=1);

namespace App\Model;

use App\Entity\PromoCode;
use App\Enum\DiscountType;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Données du formulaire d'administration d'un code promo (même principe que ProductData, ADR 0005).
 *
 * La valeur de la remise est saisie dans deux champs distincts : un pourcentage entier,
 * ou un montant en euros (converti en centimes par le formulaire). Seul le champ
 * correspondant au type choisi est obligatoire et enregistré.
 */
#[UniqueEntity(
    fields: ['code'],
    entityClass: PromoCode::class,
    identifierFieldNames: ['id'],
    message: 'Un code promo porte déjà ce nom.',
)]
final class PromoCodeData
{
    // Identifiant du code modifié, null à la création
    public ?int $id = null;

    #[Assert\NotBlank(message: 'Veuillez saisir le code.')]
    #[Assert\Length(max: 30)]
    #[Assert\Regex(pattern: '/^[A-Za-z0-9_-]+$/', message: 'Le code ne peut contenir que des lettres sans accent, des chiffres, des tirets et des tirets bas.')]
    public string $code = '';

    #[Assert\NotNull(message: 'Veuillez choisir le type de remise.')]
    public ?DiscountType $discountType = DiscountType::Percentage;

    #[Assert\Range(min: 1, max: 100, notInRangeMessage: 'Le pourcentage doit être compris entre {{ min }} et {{ max }}.')]
    public ?int $percentage = null;

    // En centimes
    #[Assert\Positive(message: 'Le montant de la remise doit être positif.')]
    public ?int $amount = null;

    public ?\DateTimeImmutable $startsAt = null;

    #[Assert\GreaterThan(propertyPath: 'startsAt', message: 'La date de fin doit être postérieure à la date de début.')]
    public ?\DateTimeImmutable $endsAt = null;

    #[Assert\Positive(message: "Le nombre maximal d'utilisations doit être positif.")]
    public ?int $maxUsages = null;

    // En centimes
    #[Assert\PositiveOrZero(message: 'Le montant minimal ne peut pas être négatif.')]
    public ?int $minimumAmount = null;

    public bool $isActive = true;

    /**
     * Le champ de la valeur est obligatoire selon le type choisi.
     */
    #[Assert\Callback]
    public function validateValue(ExecutionContextInterface $context): void
    {
        if (DiscountType::Percentage === $this->discountType && null === $this->percentage) {
            $context->buildViolation('Veuillez saisir le pourcentage de la remise.')->atPath('percentage')->addViolation();
        }

        if (DiscountType::FixedAmount === $this->discountType && null === $this->amount) {
            $context->buildViolation('Veuillez saisir le montant de la remise.')->atPath('amount')->addViolation();
        }
    }

    /**
     * Valeur à enregistrer dans PromoCode::$value, selon le type. À appeler sur des données validées.
     */
    public function getValue(): int
    {
        return (int) (DiscountType::Percentage === $this->discountType ? $this->percentage : $this->amount);
    }

    /**
     * Pré-remplit le formulaire de modification avec les valeurs actuelles du code.
     */
    public static function fromPromoCode(PromoCode $promoCode): self
    {
        $data = new self();
        $data->id = $promoCode->getId();
        $data->code = (string) $promoCode->getCode();
        $data->discountType = $promoCode->getDiscountType();
        if (DiscountType::Percentage === $promoCode->getDiscountType()) {
            $data->percentage = $promoCode->getValue();
        } else {
            $data->amount = $promoCode->getValue();
        }
        $data->startsAt = $promoCode->getStartsAt();
        $data->endsAt = $promoCode->getEndsAt();
        $data->maxUsages = $promoCode->getMaxUsages();
        $data->minimumAmount = $promoCode->getMinimumAmount();
        $data->isActive = $promoCode->isActive();

        return $data;
    }
}
