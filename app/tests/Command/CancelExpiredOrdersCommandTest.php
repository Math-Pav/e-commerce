<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\Order;
use App\Entity\ProductVariant;
use App\Entity\User;
use App\Enum\OrderStatus;
use App\Model\Cart;
use App\Model\CartLine;
use App\Model\ShippingAddress;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Test d'intégration de la commande console (vraie base de test, annulée à la fin de chaque test par DAMA).
 * Le délai d'expiration de l'environnement de test est celui de services.yaml : 60 minutes.
 */
final class CancelExpiredOrdersCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $application = new Application(self::bootKernel());
        $this->commandTester = new CommandTester($application->find('app:orders:cancel-expired'));
    }

    public function testCancelsOnlyPendingOrdersOlderThanDelay(): void
    {
        $expired = $this->placeOrder('MUG-NOIR-MAT', 3, createdMinutesAgo: 61);
        $recent = $this->placeOrder('MUG-NOIR-MAT', 2, createdMinutesAgo: 59);
        $paid = $this->placeOrder('MUG-NOIR-MAT', 1, createdMinutesAgo: 120, paid: true);

        $this->commandTester->execute([]);

        $this->commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('1 commande(s) annulée(s)', $this->commandTester->getDisplay());
        self::assertSame(OrderStatus::Cancelled, $this->findOrder($expired)->getStatus());
        self::assertSame(OrderStatus::Pending, $this->findOrder($recent)->getStatus());
        self::assertSame(OrderStatus::Paid, $this->findOrder($paid)->getStatus());
        // 12 en stock − 3 − 2 − 1 + 3 rendus par l'annulation
        self::assertSame(9, $this->findVariant('MUG-NOIR-MAT')->getStock());
    }

    public function testCustomerIsNotifiedByEmail(): void
    {
        $this->placeOrder('MUG-NOIR-MAT', 1, createdMinutesAgo: 90);

        $this->commandTester->execute([]);

        // Confirmation de la commande, puis annulation automatique
        self::assertEmailCount(2);
        $email = self::getMailerMessage(1);
        self::assertNotNull($email);
        self::assertEmailTextBodyContains($email, 'faute de paiement dans le délai');
    }

    public function testDryRunChangesNothing(): void
    {
        $expired = $this->placeOrder('MUG-NOIR-MAT', 3, createdMinutesAgo: 90);

        $this->commandTester->execute(['--dry-run' => true]);

        $this->commandTester->assertCommandIsSuccessful();
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString((string) $this->findOrder($expired)->getReference(), $display);
        self::assertStringContainsString('1 commande(s) seraient annulée(s)', $display);
        self::assertSame(OrderStatus::Pending, $this->findOrder($expired)->getStatus());
        self::assertSame(9, $this->findVariant('MUG-NOIR-MAT')->getStock());
        self::assertEmailCount(1, message: 'Seule la confirmation de commande doit être envoyée.');
    }

    public function testSecondRunCancelsNothingMore(): void
    {
        $this->placeOrder('MUG-NOIR-MAT', 3, createdMinutesAgo: 90);
        $this->commandTester->execute([]);

        $this->commandTester->execute([]);

        $this->commandTester->assertCommandIsSuccessful();
        self::assertStringContainsString('Aucune commande à annuler', $this->commandTester->getDisplay());
        self::assertSame(12, $this->findVariant('MUG-NOIR-MAT')->getStock(), 'Le stock ne doit être rendu qu\'une fois.');
    }

    public function testFailureOnOneOrderDoesNotStopTheOthers(): void
    {
        $broken = $this->placeOrder('MUG-NOIR-MAT', 1, createdMinutesAgo: 120);
        $valid = $this->placeOrder('STYLO-A-BILLE-BLEU', 3, createdMinutesAgo: 90);
        // Donnée corrompue : une quantité nulle fait échouer la remise en stock, donc toute la transaction
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE order_item SET quantity = 0 WHERE order_id = :id',
            ['id' => $broken],
        );

        $this->commandTester->execute([]);

        self::assertSame(Command::FAILURE, $this->commandTester->getStatusCode());
        $display = $this->commandTester->getDisplay();
        self::assertStringContainsString('1 commande(s) annulée(s)', $display);
        self::assertStringContainsString('1 échec(s)', $display);
        self::assertSame(OrderStatus::Pending, $this->findOrder($broken)->getStatus(), 'La transaction en échec est annulée.');
        self::assertSame(OrderStatus::Cancelled, $this->findOrder($valid)->getStatus());
        self::assertSame(200, $this->findVariant('STYLO-A-BILLE-BLEU')->getStock());
    }

    /**
     * Passe une vraie commande (stock décrémenté), puis antidate sa création directement en base :
     * l'entité ne permet pas (à raison) de modifier sa date de création.
     */
    private function placeOrder(string $sku, int $quantity, int $createdMinutesAgo, bool $paid = false): int
    {
        $order = static::getContainer()->get(OrderService::class)->placeOrder(
            $this->findCustomer(),
            new Cart([new CartLine($this->findVariant($sku), $quantity)]),
            $this->createAddress(),
        );

        if ($paid) {
            $order->markAsPaid(new \DateTimeImmutable());
            $this->getEntityManager()->flush();
        }

        $orderId = (int) $order->getId();
        $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE orders SET created_at = :createdAt WHERE id = :id',
            ['createdAt' => (new \DateTimeImmutable(sprintf('-%d minutes', $createdMinutesAgo)))->format('Y-m-d H:i:s'), 'id' => $orderId],
        );
        $this->getEntityManager()->clear();

        return $orderId;
    }

    /**
     * Relit la base avec un EntityManager vidé : on vérifie ce qui est enregistré, pas l'état en mémoire.
     */
    private function findOrder(int $orderId): Order
    {
        $this->getEntityManager()->clear();
        $order = $this->getEntityManager()->getRepository(Order::class)->find($orderId);
        self::assertNotNull($order);

        return $order;
    }

    private function findVariant(string $sku): ProductVariant
    {
        $variant = $this->getEntityManager()->getRepository(ProductVariant::class)->findOneBy(['sku' => $sku]);
        self::assertNotNull($variant);

        return $variant;
    }

    private function findCustomer(): User
    {
        $customer = $this->getEntityManager()->getRepository(User::class)->findOneBy(['email' => 'client@example.com']);
        self::assertNotNull($customer);

        return $customer;
    }

    private function createAddress(): ShippingAddress
    {
        $address = new ShippingAddress();
        $address->fullName = 'Camille Martin';
        $address->address = '12 rue des Lilas';
        $address->postalCode = '69001';
        $address->city = 'Lyon';

        return $address;
    }

    private function getEntityManager(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }
}
