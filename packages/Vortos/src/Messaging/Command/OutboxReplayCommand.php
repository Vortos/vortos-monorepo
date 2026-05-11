<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use InvalidArgumentException;
use Vortos\Messaging\Contract\OutboxPollerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Resets permanently failed outbox messages back to pending so the relay
 * worker picks them up again.
 *
 * These are rows in vortos_outbox with status='failed' — messages that
 * exhausted all automatic relay attempts (maxAttempts). Resetting them
 * sets status='pending', attempt_count=0, and clears next_attempt_at.
 *
 * The outbox relay worker picks them up on its next poll cycle.
 * This command does not re-produce to Kafka directly — it re-queues
 * for the relay worker to handle.
 *
 * Use --dry-run to inspect failed rows before resetting.
 * Use --transport to reset only messages for a specific transport.
 */
#[AsCommand(
    name: 'vortos:outbox:replay',
    description: 'Reset permanently failed outbox messages back to pending for relay retry',
)]
final class OutboxReplayCommand extends Command
{
    public function __construct(
        private OutboxPollerInterface $outboxPoller,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max messages to reset', 50)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List failed messages without resetting them')
            ->addOption('transport', null, InputOption::VALUE_OPTIONAL, 'Filter by transport name')
            ->addOption('event-class', null, InputOption::VALUE_OPTIONAL, 'Filter by event class (FQCN)')
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'Reset a single message by ID')
            ->addOption('latest', null, InputOption::VALUE_NONE, 'Process most recently failed messages first (default: oldest first)')
            ->addOption('created-from', null, InputOption::VALUE_OPTIONAL, 'Filter rows created at or after this timestamp')
            ->addOption('created-to', null, InputOption::VALUE_OPTIONAL, 'Filter rows created at or before this timestamp');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit      = (int) $input->getOption('limit');
        $dryRun     = (bool) $input->getOption('dry-run');
        $transport  = $input->getOption('transport') ?: null;
        $eventClass = $input->getOption('event-class') ?: null;
        $id         = $input->getOption('id') ?: null;
        $latest     = (bool) $input->getOption('latest');

        try {
            $createdRange = ReplayTimestampRange::fromOptions(
                $input->getOption('created-from') ?: null,
                $input->getOption('created-to') ?: null,
                '--created-from',
                '--created-to',
            );
        } catch (InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::INVALID;
        }

        $messages = $this->outboxPoller->fetchFailed($limit, $transport, $eventClass, $id, $latest, $createdRange->from, $createdRange->to);

        if (empty($messages)) {
            $output->writeln('<info>No permanently failed outbox messages found.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Found %d permanently failed message(s).</info>', count($messages)));
        $output->writeln('');

        if ($dryRun) {
            foreach ($messages as $message) {
                $output->writeln(sprintf(
                    '  • [%s]  %s  →  %s',
                    $message->id,
                    $message->eventClass,
                    $message->transportName,
                ));
                $output->writeln(sprintf('    Reason: %s', $message->failureReason ?? 'unknown'));
                $output->writeln('');
            }
            $output->writeln('<comment>Dry run — no messages reset.</comment>');
            return Command::SUCCESS;
        }

        $reset  = 0;
        $failed = 0;

        foreach ($messages as $message) {
            try {
                $this->outboxPoller->resetFailed($message->id);
                $output->writeln(sprintf('  <info>✔</info> %s  |  %s', $message->id, $message->eventClass));
                $reset++;
            } catch (\Throwable $e) {
                $this->logger->error('Failed to reset outbox message', ['id' => $message->id, 'error' => $e->getMessage()]);
                $output->writeln(sprintf('  <error>✘</error> %s — %s', $message->id, $e->getMessage()));
                $failed++;
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done.</info> Reset: <info>%d</info>  Failed: %s',
            $reset,
            $failed > 0 ? sprintf('<error>%d</error>', $failed) : '<info>0</info>',
        ));
        $output->writeln('<comment>The outbox relay worker will pick up the reset messages on its next poll.</comment>');

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
