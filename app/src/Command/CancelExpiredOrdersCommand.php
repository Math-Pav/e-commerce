<?php

declare(strict_types=1);

namespace App\Command;

use App\Repository\OrderRepository;
use App\Service\OrderService;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Annule les commandes restées en attente de paiement au-delà du délai configuré
 * (voir docs/adr/0024-annulation-automatique-des-commandes.md).
 * Lancée par le cron du serveur toutes les 5 minutes ; peut être relancée sans risque (idempotente).
 */
#[AsCommand(name: 'app:orders:cancel-expired', description: 'Annule les commandes en attente de paiement depuis trop longtemps')]
final class CancelExpiredOrdersCommand extends Command
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly OrderService $orderService,
        private readonly ManagerRegistry $doctrine,
        private readonly LoggerInterface $logger,
        #[Autowire('%app.order.payment_delay_minutes%')]
        private readonly int $paymentDelayMinutes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Affiche les commandes qui seraient annulées, sans rien modifier');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dryRun = (bool) $input->getOption('dry-run');
        $createdBefore = new \DateTimeImmutable(sprintf('-%d minutes', $this->paymentDelayMinutes));
        $orderIds = $this->orderRepository->findExpiredPendingIds($createdBefore);

        if ([] === $orderIds) {
            $io->success(sprintf('Aucune commande à annuler (délai : %d minutes).', $this->paymentDelayMinutes));

            return Command::SUCCESS;
        }

        $cancelled = 0;
        $skipped = 0;
        $failed = 0;

        // Une commande à la fois, chacune dans sa propre transaction (celle de OrderService)
        foreach ($orderIds as $orderId) {
            $order = $this->orderRepository->find($orderId);

            // Payée ou annulée depuis la recherche : plus rien à faire
            if (null === $order || !$order->canBeCancelled()) {
                ++$skipped;
                continue;
            }

            $label = sprintf('%s (créée le %s)', $order->getReference(), $order->getCreatedAt()->format('d/m/Y H:i'));

            if ($dryRun) {
                $io->writeln('À annuler : '.$label);
                ++$cancelled;
                continue;
            }

            try {
                $this->orderService->cancelExpired($order);
                $io->writeln('Annulée : '.$label);
                ++$cancelled;
            } catch (\Exception $exception) {
                // \Exception et non \Throwable : une erreur de programmation (TypeError…) arrête la commande
                ++$failed;
                $io->writeln(sprintf('<error>Échec : %s : %s</error>', $label, $exception->getMessage()));
                $this->logger->error('Échec de l\'annulation automatique de la commande n°{orderId}.', [
                    'orderId' => $orderId,
                    'exception' => $exception,
                ]);
                // Une exception dans une transaction ferme l'EntityManager : on en repart un neuf pour les suivantes
                $this->doctrine->resetManager();
            }
        }

        $summary = $dryRun
            ? sprintf('%d commande(s) seraient annulée(s), %d ignorée(s) (simulation, rien n\'a été modifié).', $cancelled, $skipped)
            : sprintf('%d commande(s) annulée(s), %d ignorée(s), %d échec(s).', $cancelled, $skipped, $failed);

        if ($failed > 0) {
            $io->error($summary);

            return Command::FAILURE;
        }

        $io->success($summary);

        return Command::SUCCESS;
    }
}
