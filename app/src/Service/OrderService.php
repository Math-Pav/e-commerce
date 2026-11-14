<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\PromoCode;
use App\Entity\User;
use App\Exception\InsufficientStockException;
use App\Exception\InvalidPromoCodeException;
use App\Model\Cart;
use App\Model\ShippingAddress;
use App\Repository\OrderRepository;
use App\Repository\ProductVariantRepository;
use App\Repository\PromoCodeRepository;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class OrderService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductVariantRepository $variantRepository,
        private readonly OrderRepository $orderRepository,
        private readonly PromoCodeRepository $promoCodeRepository,
        private readonly DiscountCalculator $discountCalculator,
        private readonly CustomerMailer $customerMailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Crée la commande, décrémente le stock et compte l'utilisation du code promo,
     * en une seule transaction : tout réussit, ou rien ne change.
     * Si le client l'a demandé, l'adresse de livraison est ensuite enregistrée dans son profil.
     *
     * @throws InsufficientStockException
     * @throws InvalidPromoCodeException  si le code promo n'est plus applicable
     */
    public function placeOrder(User $customer, Cart $cart, ShippingAddress $address): Order
    {
        if ($cart->isEmpty()) {
            throw new \DomainException('Impossible de passer commande avec un panier vide.');
        }

        $order = $this->entityManager->wrapInTransaction(function () use ($customer, $cart, $address): Order {
            $order = (new Order())
                ->setCustomer($customer)
                ->setReference($this->generateReference())
                ->setShippingFullName($address->fullName)
                ->setShippingAddress($address->address)
                ->setShippingPostalCode($address->postalCode)
                ->setShippingCity($address->city);

            foreach ($cart->lines as $line) {
                $variant = $line->variant;

                // Le verrou relit aussi la variante : stock et prix sont ceux de la base, à jour
                $this->variantRepository->lockForUpdate($variant);
                $variant->decreaseStock($line->quantity);
                $product = $line->getProduct();

                // Copie du nom, du libellé et du prix : la commande ne changera pas si le produit ou la variante changent
                $order->addItem(
                    (new OrderItem())
                        ->setProduct($product)
                        ->setVariant($variant)
                        ->setProductName($product->getName() ?? throw new \LogicException('Produit sans nom.'))
                        ->setVariantLabel($variant->getLabel())
                        ->setUnitPrice($variant->getEffectivePrice())
                        ->setQuantity($line->quantity),
                );
            }

            $order->recalculateTotal();

            if (null !== $cart->promoCode) {
                $this->applyPromoCode($order, $cart->promoCode);
            }

            $this->entityManager->persist($order);

            return $order;
        });

        if ($address->saveToProfile) {
            $this->saveAddressToProfile($customer, $address);
        }

        // Après la transaction : si elle avait échoué, aucun e-mail n'annoncerait une commande inexistante
        $this->customerMailer->sendOrderConfirmation($order);

        return $order;
    }

    /**
     * Annulation demandée par le client ou par l'administration.
     *
     * Ne dépend ni de la requête ni de l'utilisateur connecté : c'est à l'appelant
     * de vérifier qui a le droit d'annuler.
     *
     * @throws \DomainException si la commande ne peut pas (ou plus) être annulée
     */
    public function cancel(Order $order): void
    {
        $this->cancelPendingOrder($order);
        $this->customerMailer->sendOrderCancellation($order);
    }

    /**
     * Annulation automatique d'une commande restée trop longtemps sans paiement
     * (commande console app:orders:cancel-expired, voir docs/adr/0024-annulation-automatique-des-commandes.md).
     * Même logique que cancel() ; seul l'e-mail explique la raison au client.
     *
     * @throws \DomainException si la commande ne peut pas (ou plus) être annulée
     */
    public function cancelExpired(Order $order): void
    {
        $this->cancelPendingOrder($order);
        $this->customerMailer->sendExpiredOrderCancellation($order);
    }

    /**
     * @throws \DomainException si la commande n'est pas payée
     */
    public function ship(Order $order): void
    {
        $order->markAsShipped(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * @throws \DomainException si la commande n'est pas expédiée
     */
    public function deliver(Order $order): void
    {
        $order->markAsDelivered(new \DateTimeImmutable());
        $this->entityManager->flush();
    }

    /**
     * Annule une commande en attente de paiement et remet en stock les variantes commandées,
     * en une seule transaction : tout réussit, ou rien ne change.
     *
     * @throws \DomainException si la commande ne peut pas (ou plus) être annulée
     */
    private function cancelPendingOrder(Order $order): void
    {
        // Vérification avant la transaction : un refus « normal » ne touche pas à la base
        if (!$order->canBeCancelled()) {
            throw new \DomainException('Seule une commande en attente de paiement peut être annulée.');
        }

        $this->entityManager->wrapInTransaction(function () use ($order): void {
            // Verrou sur la commande : deux annulations simultanées ne remettent pas le stock deux fois,
            // et un paiement simultané attend (PaymentService prend le même verrou avant de débiter).
            // La relecture peut révéler un statut changé entre-temps : cancel() lève alors l'exception.
            $this->orderRepository->lockForUpdate($order);
            $order->cancel(new \DateTimeImmutable());

            foreach ($order->getItems() as $item) {
                $variant = $item->getVariant();

                // Variante (ou produit) supprimée depuis la commande : rien à remettre en stock
                if (null === $variant) {
                    continue;
                }

                // Même verrou que placeOrder() : aucune autre commande ne modifie ce stock en même temps
                $this->variantRepository->lockForUpdate($variant);
                $variant->increaseStock((int) $item->getQuantity());
            }

            // L'utilisation du code est rendue, sous le même verrou que lors de la commande
            $promoCode = $order->getPromoCode();
            if (null !== $promoCode) {
                $this->promoCodeRepository->lockForUpdate($promoCode);
                $promoCode->decrementUsage();
            }
        });
    }

    /**
     * Appelée DANS la transaction de placeOrder(). Le verrou sur le code fait attendre une commande
     * simultanée avec le même code jusqu'à la fin de cette transaction : elle relit alors le compteur
     * à jour, et le nombre maximal d'utilisations ne peut pas être dépassé.
     * La remise est recalculée sur le sous-total de la commande (prix relus sous verrou), pas sur celui du panier.
     *
     * @throws InvalidPromoCodeException
     */
    private function applyPromoCode(Order $order, PromoCode $promoCode): void
    {
        $this->promoCodeRepository->lockForUpdate($promoCode);

        $subtotal = (int) $order->getSubtotalAmount();
        $promoCode->assertApplicable(new \DateTimeImmutable(), $subtotal);
        $promoCode->incrementUsage();

        $order->applyDiscount($promoCode, $this->discountCalculator->calculate($promoCode, $subtotal));
    }

    /**
     * Appelée APRÈS la transaction de la commande, volontairement :
     * - une commande qui échoue ne modifie pas le profil ;
     * - la commande est déjà enregistrée : si le profil ne peut pas l'être, on journalise l'erreur
     *   au lieu de la propager. Sinon le client verrait une erreur alors que sa commande existe,
     *   son panier ne serait pas vidé, et il risquerait de commander une seconde fois.
     *
     * Pas de validation ici : l'adresse de livraison a déjà été validée, avec des règles
     * au moins aussi strictes que celles du profil (mêmes contraintes, plus NotBlank).
     */
    private function saveAddressToProfile(User $customer, ShippingAddress $address): void
    {
        $customer
            ->setAddress($address->address)
            ->setPostalCode($address->postalCode)
            ->setCity($address->city);

        try {
            $this->entityManager->flush();
        } catch (DBALException $exception) {
            // Seules les erreurs de base de données sont absorbées ; un bug de programmation reste visible
            $this->logger->error("L'adresse de livraison n'a pas pu être enregistrée dans le profil du client.", [
                'customer_id' => $customer->getId(),
                'exception' => $exception,
            ]);
        }
    }

    private function generateReference(): string
    {
        // Exemple : CMD-20261112-4F9A2C
        return sprintf('CMD-%s-%s', date('Ymd'), strtoupper(bin2hex(random_bytes(3))));
    }
}
