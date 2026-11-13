<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Model\SellerDetails;
use App\Service\CustomerMailer;
use App\Service\InvoicePdfGenerator;
use App\Service\VatCalculator;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

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
        (new CustomerMailer($mailer, self::createPdfGenerator(), new NullLogger(), 'boutique@example.test', 'Boutique de test'))
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
        (new CustomerMailer($mailer, self::createPdfGenerator(), $logger, 'boutique@example.test', 'Boutique de test'))
            ->sendPaymentConfirmation(...self::createPaidOrderWithInvoice());
    }

    public function testPaymentConfirmationHasInvoiceAttached(): void
    {
        $sentEmail = null;
        $mailer = $this->createMock(MailerInterface::class);
        $mailer->expects(self::once())
            ->method('send')
            ->willReturnCallback(static function (RawMessage $message) use (&$sentEmail): void {
                $sentEmail = $message;
            });

        [$order, $invoice] = self::createPaidOrderWithInvoice();
        (new CustomerMailer($mailer, self::createPdfGenerator(), new NullLogger(), 'boutique@example.test', 'Boutique de test'))
            ->sendPaymentConfirmation($order, $invoice);

        self::assertInstanceOf(TemplatedEmail::class, $sentEmail);
        self::assertSame($invoice, $sentEmail->getContext()['invoice']);
        $attachments = $sentEmail->getAttachments();
        self::assertCount(1, $attachments);
        self::assertSame('FA-2026-000001.pdf', $attachments[0]->getFilename());
        self::assertSame('application/pdf', $attachments[0]->getContentType());
        self::assertStringStartsWith('%PDF', $attachments[0]->getBody());
    }

    /**
     * Vrai générateur, avec un template minimal en mémoire : le test ne dépend pas du template réel.
     */
    public static function createPdfGenerator(): InvoicePdfGenerator
    {
        return new InvoicePdfGenerator(new Environment(new ArrayLoader([
            'invoice/pdf.html.twig' => '<p>Facture {{ invoice.number }}</p>',
        ])));
    }

    /**
     * @return array{Order, Invoice}
     */
    public static function createPaidOrderWithInvoice(): array
    {
        $customer = (new User())->setEmail('camille@example.com')->setFirstName('Camille')->setLastName('Martin');
        $order = (new Order())
            ->setCustomer($customer)
            ->setReference('CMD-TEST-1')
            ->setShippingFullName('Camille Martin')
            ->setShippingAddress('12 rue des Lilas')
            ->setShippingPostalCode('69001')
            ->setShippingCity('Lyon')
            ->addItem((new OrderItem())->setProductName('Mug')->setUnitPrice(1200)->setQuantity(1));
        $order->recalculateTotal();
        $order->markAsPaid(new \DateTimeImmutable('2026-11-13 10:00'));

        $invoice = Invoice::issue(
            $order,
            'FA-2026-000001',
            new \DateTimeImmutable('2026-11-13 10:00'),
            new SellerDetails('Vendeur', '1 rue A', '69000', 'Lyon', '12345678900017', 'FR40123456789'),
            (new VatCalculator())->fromAmountIncludingTax(1200, 20),
        );

        return [$order, $invoice];
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
