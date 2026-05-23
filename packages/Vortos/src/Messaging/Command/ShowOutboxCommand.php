<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use Vortos\Messaging\Contract\OutboxPollerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vortos:outbox:show',
    description: 'Show full details of a single outbox row',
)]
final class ShowOutboxCommand extends Command
{
    public function __construct(
        private readonly OutboxPollerInterface $poller,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'Outbox row ID (UUID)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id  = $input->getArgument('id');
        $row = $this->poller->findById($id);

        if ($row === null) {
            $output->writeln(sprintf('<error>Outbox row "%s" not found.</error>', $id));
            return Command::FAILURE;
        }

        $statusDisplay = match ($row->status) {
            'pending'   => '<fg=yellow>pending</>',
            'published' => '<info>published</>',
            'failed'    => '<error>failed</>',
            default     => $row->status,
        };

        $output->writeln(sprintf('<info>Outbox row %s</info>', $row->id));
        $output->writeln('');
        $output->writeln(sprintf('  <fg=gray>Status:</>        %s', $statusDisplay));
        $output->writeln(sprintf('  <fg=gray>Transport:</>     %s', $row->transportName));
        $output->writeln(sprintf('  <fg=gray>Event:</>         %s', $row->payloadType));
        $output->writeln(sprintf('  <fg=gray>Schema:</>        v%d', $row->schemaVersion));
        $output->writeln('');
        $output->writeln(sprintf('  <fg=gray>Event ID:</>      %s', $row->eventId));
        $output->writeln(sprintf('  <fg=gray>Aggregate:</>     %s  (%s)', $row->aggregateId, $row->aggregateType));
        $output->writeln(sprintf('  <fg=gray>Version:</>       %d', $row->aggregateVersion));
        $output->writeln('');
        $output->writeln(sprintf('  <fg=gray>Occurred At:</>   %s', $row->occurredAt->format('Y-m-d H:i:s')));
        $output->writeln(sprintf('  <fg=gray>Created At:</>    %s', $row->createdAt->format('Y-m-d H:i:s')));

        if ($row->publishedAt !== null) {
            $output->writeln(sprintf('  <fg=gray>Published At:</> %s', $row->publishedAt->format('Y-m-d H:i:s')));
        }

        $output->writeln(sprintf('  <fg=gray>Attempts:</>      %d', $row->attemptCount));

        if ($row->nextAttemptAt !== null) {
            $output->writeln(sprintf('  <fg=gray>Next Attempt:</>  %s', $row->nextAttemptAt->format('Y-m-d H:i:s')));
        }

        if ($row->correlationId !== null) {
            $output->writeln('');
            $output->writeln(sprintf('  <fg=gray>Correlation:</>  %s', $row->correlationId));
        }
        if ($row->causationId !== null) {
            $output->writeln(sprintf('  <fg=gray>Causation:</>    %s', $row->causationId));
        }
        if ($row->traceId !== null) {
            $output->writeln(sprintf('  <fg=gray>Trace:</>        %s', $row->traceId));
        }

        if (!empty($row->metadata)) {
            $output->writeln('');
            $output->writeln('  <fg=gray>Metadata:</>');
            foreach ($row->metadata as $k => $v) {
                $output->writeln(sprintf('    <fg=gray>%s:</> %s', $k, is_scalar($v) ? $v : json_encode($v)));
            }
        }

        if ($row->failureReason !== null) {
            $output->writeln('');
            $output->writeln(sprintf('  <fg=gray>Failure Reason:</> <error>%s</error>', $row->failureReason));
        }

        $output->writeln('');
        $output->writeln('  <fg=gray>Payload:</>');
        $formatted = json_encode(json_decode($row->payload, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        foreach (explode("\n", $formatted ?: $row->payload) as $line) {
            $output->writeln('    ' . $line);
        }

        return Command::SUCCESS;
    }
}
