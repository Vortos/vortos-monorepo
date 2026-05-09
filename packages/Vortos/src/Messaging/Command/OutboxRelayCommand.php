<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use Vortos\Messaging\Contract\OutboxPollerInterface;
use Vortos\Messaging\Runtime\OutboxRelayRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vortos:outbox:relay',
    description: 'Start the outbox relay worker — polls outbox table and produces to broker'
)]
final class OutboxRelayCommand extends Command
{
    public function __construct(
        private OutboxRelayRunner $runner,
        private OutboxPollerInterface $outboxPoller,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Messages per relay batch', 100)
            ->addOption('sleep-ms', null, InputOption::VALUE_OPTIONAL, 'Milliseconds to sleep when queue is empty', 500)
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Stop after N seconds (0 = run forever)', 0)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List pending messages without relaying them');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $batchSize = (int) $input->getOption('batch-size');
        $sleepMs   = (int) $input->getOption('sleep-ms');
        $timeout   = (int) $input->getOption('timeout');
        $dryRun    = (bool) $input->getOption('dry-run');

        if ($dryRun) {
            $messages = $this->outboxPoller->fetchPending($batchSize);

            if (empty($messages)) {
                $output->writeln('<info>No pending outbox messages.</info>');
                return Command::SUCCESS;
            }

            $output->writeln(sprintf('<info>%d pending outbox message(s):</info>', count($messages)));
            $output->writeln('');

            foreach ($messages as $message) {
                $output->writeln(sprintf(
                    '  • [%s]  %s  →  %s',
                    $message->id,
                    $message->eventClass,
                    $message->transportName,
                ));
            }

            $output->writeln('');
            $output->writeln('<comment>Dry run — no messages relayed.</comment>');
            return Command::SUCCESS;
        }

        if ($timeout > 0) {
            if (extension_loaded('pcntl')) {
                pcntl_signal(SIGALRM, fn() => $this->runner->stop());
                pcntl_alarm($timeout);
                $output->writeln("<comment>Timeout set to {$timeout}s</comment>");
            } else {
                $output->writeln('<comment>Warning: pcntl not available, timeout option ignored</comment>');
            }
        }

        $output->writeln("<info>Starting outbox relay worker...</info>");

        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGTERM, fn() => $this->runner->stop());
            pcntl_signal(SIGINT, fn() => $this->runner->stop());
        }

        try {

            $this->runner->run($batchSize, $sleepMs);
        } catch (\Throwable $e) {

            $this->logger->error(
                'Outbox relay worker failed',
                [
                    'exception' => $e->getMessage()
                ]
            );

            $output->writeln("<error>Outbox relay worker failed: {$e->getMessage()}</error>");

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}