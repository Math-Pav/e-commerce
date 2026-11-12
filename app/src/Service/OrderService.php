<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Exception\InsufficientStockException;
use App\Model\Cart;
use App\Model\ShippingAddress;
use App\Repository\ProductRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OrderService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly ProductRepository $productRepository,
    ) {
    }

    /**
     * Crée la commande et décrémente le stock, en une seule transaction : tout réussit, ou rien ne change.
     *
     * @throws InsufficientStockException
     */
    public function placeOrder(User $customer, Cart $cart, ShippingAddress $address): Order
    {
        if ($cart->isEmpty()) {
            throw new \DomainException('Impossible de passer commande avec un panier vide.');
        }

        return $this->entityManager->wrapInTransaction(function () use ($customer, $cart, $address): Order {
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
                        ->setProductName($product->getName())
                        ->setUnitPrice($product->getPrice())
                        ->setQuantity($line->quantity),
                );
            }

            $order->recalculateTotal();
            $this->entityManager->persist($order);

            return $order;
        });
    }

    private function generateReference(): string
    {
        // Exemple : CMD-20261112-4F9A2C
        return sprintf('CMD-%s-%s', date('Ymd'), strtoupper(bin2hex(random_bytes(3))));
    }
}