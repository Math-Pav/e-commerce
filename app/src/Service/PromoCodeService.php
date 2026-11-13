<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\PromoCode;
use App\Model\PromoCodeData;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Gestion des codes promo depuis l'administration : création, modification, activation.
 */
final class PromoCodeService
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    /**
     * @param PromoCodeData $data données déjà validées par le formulaire
     */
    public function create(PromoCodeData $data): PromoCode
    {
        $promoCode = new PromoCode();
        $this->applyData($promoCode, $data);

        $this->entityManager->persist($promoCode);
        $this->entityManager->flush();

        return $promoCode;
    }

    /**
     * Le nombre d'utilisations n'est pas modifiable : il est tenu à jour par les commandes.
     * Les commandes passées gardent leur copie du code et de la remise.
     *
     * @param PromoCodeData $data données déjà validées par le formulaire
     */
    public function update(PromoCode $promoCode, PromoCodeData $data): void
    {
        $this->applyData($promoCode, $data);
        $this->entityManager->flush();
    }

    public function activate(PromoCode $promoCode): void
    {
        $promoCode->setIsActive(true);
        $this->entityManager->flush();
    }

    public function deactivate(PromoCode $promoCode): void
    {
        $promoCode->setIsActive(false);
        $this->entityManager->flush();
    }

    private function applyData(PromoCode $promoCode, PromoCodeData $data): void
    {
        $promoCode
            ->setCode($data->code)
            ->setDiscountType($data->discountType ?? throw new \LogicException('Type de remise manquant.'))
            ->setValue($data->getValue())
            ->setStartsAt($data->startsAt)
            ->setEndsAt($data->endsAt)
            ->setMaxUsages($data->maxUsages)
            ->setMinimumAmount($data->minimumAmount)
            ->setIsActive($data->isActive);
    }
}
