<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use InvalidArgumentException;
use Vortos\Messaging\Contract\ProducerInterface;
use Vortos\Messaging\DeadLetter\DeadLetterRepository;
use Vortos\Messaging\Registry\HandlerRegistry;
use Vortos\Messaging\Registry\TransportRegistry;
use Vortos\Messaging\Serializer\SerializerLocator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Replays permanently failed consumer messages by re-producing them to their
 * original Kafka transport. The consumer worker will pick them up and retry.
 *
 * These are messages that exhausted all in-process retry attempts and were
 * written to vortos_failed_messages by DeadLetterWriter. They are distinct
 * from outbox relay failures — use vortos:outbox:replay for those.
 *
 * Use --dry-run to inspect messages before replaying.
 * Use --transport or --event-class to replay a targeted subset.
 * Use --id to replay a single specific message.
 */
#[AsCommand(
    name: 'vortos:dlq:replay',
    description: 'Replay permanently failed consumer messages back to their Kafka transport',
)]
final class ReplayDeadLetterCommand extends Command
{
    private string $replaySecret = '';

    public function setReplaySecret(string $secret): void
    {
        $this->replaySecret = $secret;
    }

    public function __construct(
        private DeadLetterRepository $repository,
        private ProducerInterface $producer,
        private SerializerLocator $serializerLocator,
        private HandlerRegistry $handlerRegistry,
        private TransportRegistry $transportRegistry,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function configure(): void
    {
        $this
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max messages to replay', 50)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List messages without replaying them')
            ->addOption('transport', null, InputOption::VALUE_OPTIONAL, 'Filter by transport name')
            ->addOption('event-class', null, InputOption::VALUE_OPTIONAL, 'Filter by event class (FQCN)')
            ->addOption('id', null, InputOption::VALUE_OPTIONAL, 'Replay a single message by ID')
            ->addOption('latest', null, InputOption::VALUE_NONE, 'Process most recently failed messages first (default: oldest first)')
            ->addOption('failed-from', null, InputOption::VALUE_OPTIONAL, 'Filter messages failed at or after this timestamp')
            ->addOption('failed-to', null, InputOption::VALUE_OPTIONAL, 'Filter messages failed at or before this timestamp');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit      = max(1, min((int) $input->getOption('limit'), 10000));
        $dryRun     = (bool) $input->getOption('dry-run');
        $transport  = $input->getOption('transport') ?: null;
        $eventClass = $input->getOption('event-class') ?: null;
        $id         = $input->getOption('id') ?: null;
        $latest     = (bool) $input->getOption('latest');

        try {
            $failedRange = ReplayTimestampRange::fromOptions(
                $input->getOption('failed-from') ?: null,
                $input->getOption('failed-to') ?: null,
                '--failed-from',
                '--failed-to',
            );
        } catch (InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::INVALID;
        }

        $rows = $this->repository->fetchFailed($limit, $transport, $eventClass, $id, $latest, $failedRange->from, $failedRange->to);

        if (empty($rows)) {
            $output->writeln('<info>No failed messages found.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Found %d failed message(s).</info>', count($rows)));
        $output->writeln('');

        if ($dryRun) {
            foreach ($rows as $row) {
                $output->writeln(sprintf(
                    '  • [%s]  %s  →  %s',
                    $row['id'],
                    $row['event_class'],
                    $row['transport_name'],
                ));
                $output->writeln(sprintf('    Reason: %s', $row['failure_reason']));
                $output->writeln(sprintf('    Failed: %s', $row['failed_at']));
                $output->writeln('');
            }
            $output->writeln('<comment>Dry run — no messages replayed.</comment>');
            return Command::SUCCESS;
        }

        $replayed = 0;
        $failed   = 0;

        foreach ($rows as $row) {
            try {
                if (!$this->handlerRegistry->isKnownEventClass($row['event_class'])) {
                    throw new \UnexpectedValueException(
                        "Unknown event class '{$row['event_class']}' — not registered in HandlerRegistry."
                    );
                }

                if (!$this->transportRegistry->has($row['transport_name'])) {
                    throw new \UnexpectedValueException(
                        "Unknown transport '{$row['transport_name']}' — not registered in TransportRegistry."
                    );
                }

                $serializer = $this->serializerLocator->locate('json');
                $event      = $serializer->deserialize($row['payload'], $row['event_class']);
                $headers    = json_decode($row['headers'], true, 512, JSON_THROW_ON_ERROR);

                $headers['x-vortos-global-replays'] = ($headers['x-vortos-global-replays'] ?? 0) + 1;
                $headers['x-vortos-target-handler'] = $row['handler_id'];
                $headers['x-vortos-replay-sig'] = $this->replaySecret !== ''
                    ? hash_hmac('sha256', $row['handler_id'], $this->replaySecret)
                    : '';

                unset($headers['x-vortos-failure-reason']);
                unset($headers['x-vortos-failed-at']);

                $this->producer->produce($row['transport_name'], $event, $headers);
                $this->repository->markReplayed($row['id']);

                $output->writeln(sprintf('  <info>✔</info> %s  |  %s', $row['id'], $row['event_class']));
                $replayed++;
            } catch (\Throwable $e) {
                $this->logger->error('DLQ replay failed', ['id' => $row['id'], 'error' => $e->getMessage()]);
                $output->writeln(sprintf('  <error>✘</error> %s — %s', $row['id'], $e->getMessage()));
                $failed++;
            }
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '<info>Done.</info> Replayed: <info>%d</info>  Failed: %s',
            $replayed,
            $failed > 0 ? sprintf('<error>%d</error>', $failed) : '<info>0</info>',
        ));

        return $failed === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
