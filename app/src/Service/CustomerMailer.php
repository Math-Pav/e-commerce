<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Invoice;
use App\Entity\Order;
use App\Entity\User;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Envoie les e-mails destinés aux clients, aux moments clés de leur parcours.
 *
 * Un e-mail est un « bonus » : son échec ne doit jamais annuler l'action du client
 * (inscription, commande, paiement). Les erreurs d'envoi sont donc journalisées, pas propagées.
 */
final class CustomerMailer
{
    public function __construct(
        private readonly MailerInterface $mailer,
        private readonly InvoicePdfGenerator $invoicePdfGenerator,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.mailer.sender_email%')]
        private readonly string $senderEmail,
        #[Autowire('%app.mailer.sender_name%')]
        private readonly string $senderName,
    ) {
    }

    public function sendWelcome(User $user): void
    {
        $this->send($this->createEmail($user, 'Bienvenue chez Atelier Papier', 'welcome', ['user' => $user]), $user);
    }

    public function sendOrderConfirmation(Order $order): void
    {
        $this->sendForOrder($order, 'Confirmation de votre commande %s', 'order_confirmation');
    }

    /**
     * La facture est jointe en PDF (voir docs/adr/0018-factures.md).
     */
    public function sendPaymentConfirmation(Order $order, Invoice $invoice): void
    {
        $this->sendForOrder($order, 'Paiement reçu pour votre commande %s', 'payment_confirmation', $invoice);
    }

    public function sendOrderCancellation(Order $order): void
    {
        $this->sendForOrder($order, 'Annulation de votre commande %s', 'order_cancellation', context: ['expired' => false]);
    }

    /**
     * Même e-mail que l'annulation, avec un texte qui explique l'annulation automatique faute de paiement.
     */
    public function sendExpiredOrderCancellation(Order $order): void
    {
        $this->sendForOrder($order, 'Annulation de votre commande %s', 'order_cancellation', context: ['expired' => true]);
    }

    /**
     * @param array<string, mixed> $context variables propres au template, en plus de la commande et de la facture
     */
    private function sendForOrder(Order $order, string $subjectFormat, string $template, ?Invoice $invoice = null, array $context = []): void
    {
        $customer = $order->getCustomer();

        if (null === $customer) {
            throw new \LogicException('Une commande sans client ne peut pas donner lieu à un e-mail.');
        }

        $email = $this->createEmail($customer, sprintf($subjectFormat, $order->getReference()), $template, ['order' => $order, 'invoice' => $invoice] + $context);

        if (null !== $invoice) {
            $email->attach($this->invoicePdfGenerator->generate($invoice), $invoice->getFilename(), 'application/pdf');
        }

        $this->send($email, $customer);
    }

    /**
     * @param array<string, mixed> $context variables transmises aux templates
     *                                      (« email » est réservé par Symfony)
     */
    private function createEmail(User $recipient, string $subject, string $template, array $context): TemplatedEmail
    {
        return (new TemplatedEmail())
            ->from(new Address($this->senderEmail, $this->senderName))
            ->to(new Address((string) $recipient->getEmail(), sprintf('%s %s', $recipient->getFirstName(), $recipient->getLastName())))
            ->subject($subject)
            ->htmlTemplate(sprintf('emails/%s.html.twig', $template))
            ->textTemplate(sprintf('emails/%s.txt.twig', $template))
            ->context($context);
    }

    private function send(TemplatedEmail $email, User $recipient): void
    {
        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface $exception) {
            // L'identifiant suffit pour enquêter : inutile d'écrire l'adresse e-mail dans les journaux
            $this->logger->error('Échec de l\'envoi de l\'e-mail « {subject} » au client n°{userId}.', [
                'subject' => $email->getSubject(),
                'userId' => $recipient->getId(),
                'exception' => $exception,
            ]);
        }
    }
}
