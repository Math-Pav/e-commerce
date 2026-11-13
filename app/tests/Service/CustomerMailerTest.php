<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Order;
use App\Entity\User;
use App\Service\CustomerMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

final class CustomerMailerTest extends TestCase
{
    public function testOrderConfirmationIsSentToCustomerFromConfiguredSender(): void
    {
        $sentEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->willReturnCallback(static function (RawMessage $message) use (&$sentEmail): void {
                $sentEmail = $message;
            });

        $order = $this->createOrder();
        (new CustomerMailer($mailer, new NullLogger(), 'boutique@example.test', 'Boutique de test'))
            ->sendOrderConfirmation($order);

        self::assertInstanceOf(TemplatedEmail::class, $sentEmail);
        self::assertSame('boutique@example.test', $sentEmail->getFrom()[0]->getAddress());
        self::assertSame('Boutique de test', $sentEmail->getFrom()[0]->getName());
        self::assertSame('camille@example.com', $sentEmail->getTo()[0]->getAddress());
        self::assertSame('Confirmation de votre commande CMD-TEST-1', $sentEmail->getSubject());
        self::assertSame('emails/order_confirmation.html.twig', $sentEmail->getHtmlTemplate());
        self::assertSame('emails/order_confirmation.txt.twig', $sentEmail->getTextTemplate());
        self::assertSame($order, $sentEmail->getContext()['order']);
    }

    public function testTransportFailureIsLoggedInsteadOfThrown(): void
    {
        $mailer = $this->createStub(MailerInterface::class);
        $mailer->method('send')->willThrowException(new TransportException('Serveur SMTP injoignable'));

        // Un mock vérifie un comportement : ici, que l'échec est bien journalisé
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error');

        // Aucune exception ne doit remonter jusqu'à l'appelant
        (new CustomerMailer($mailer, $logger, 'boutique@example.test', 'Boutique de test'))
            ->sendPaymentConfirmation($this->createOrder());
    }

    private function createOrder(): Order
    {
        $customer = (new User())
            ->setEmail('camille@example.com')
            ->setFirstName('Camille')
            ->setLastName('Martin');

        return (new Order())
            ->setCustomer($customer)
            ->setReference('CMD-TEST-1');
    }
}
