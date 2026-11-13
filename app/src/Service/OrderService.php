<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Exception\InsufficientStockException;
use App\Model\Cart;
use App\Model\ShippingAddress;
use App\Repository\OrderRepository;
use App\Repository\ProductRepository;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class OrderService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
        private readonly OrderRepository $orderRepository,
        private readonly CustomerMailer $customerMailer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Crée la commande et décrémente le stock, en une seule transaction : tout réussit, ou rien ne change.
     * Si le client l'a demandé, l'adresse de livraison est ensuite enregistrée dans son profil.
     *
     * @throws InsufficientStockException
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
                $product = $line->product;

                $this->productRepository->lockForUpdate($product);
                $product->decreaseStock($line->quantity);

                // Copie du nom et du prix : la commande ne changera pas si le produit change ensuite
                $order->addItem(
                    (new OrderItem())
                        ->setProduct($product)
                        ->setProductName($product->getName() ?? throw new \LogicException('Produit sans nom.'))
                        ->setUnitPrice($product->getPrice() ?? throw new \LogicException('Produit sans prix.'))
                        ->setQuantity($line->quantity),
                );
            }

            $order->recalculateTotal();
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
     * Annule une commande en attente de paiement et remet en stock les produits commandés,
     * en une seule transaction : tout réussit, ou rien ne change.
     *
     * Ne dépend ni de la requête ni de l'utilisateur connecté : elle peut être appelée par
     * l'administration, par le client ou par une tâche automatique. C'est à l'appelant
     * de vérifier qui a le droit d'annuler.
     *
     * @throws \DomainException si la commande ne peut pas (ou plus) être annulée
     */
    public function cancel(Order $order): void
    {
        // Vérification avant la transaction : un refus « normal » ne touche pas à la base
        if (!$order->canBeCancelled()) {
            throw new \DomainException('Seule une commande en attente de paiement peut être annulée.');
        }

        $this->entityManager->wrapInTransaction(function () use ($order): void {
            // Verrou sur la commande : deux annulations simultanées ne remettent pas le stock deux fois.
            // La relecture peut révéler un statut changé entre-temps : cancel() lève alors l'exception.
            $this->orderRepository->lockForUpdate($order);
            $order->cancel(new \DateTimeImmutable());

            foreach ($order->getItems() as $item) {
                $product = $item->getProduct();

                // Produit supprimé du catalogue depuis la commande : rien à remettre en stock
                if ($product === null) {
                    continue;
                }

                // Même verrou que placeOrder() : aucune autre commande ne modifie ce stock en même temps
                $this->productRepository->lockForUpdate($product);
                $product->increaseStock((int) $item->getQuantity());
            }
        });

        $this->customerMailer->sendOrderCancellation($order);
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