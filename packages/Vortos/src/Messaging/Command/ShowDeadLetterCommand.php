<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use Vortos\Messaging\DeadLetter\DeadLetterRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vortos:dlq:show',
    description: 'Show full details of a single dead letter queue entry',
)]
final class ShowDeadLetterCommand extends Command
{
    public function __construct(
        private readonly DeadLetterRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::REQUIRED, 'DLQ entry ID (UUID)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $id  = $input->getArgument('id');
        $row = $this->repository->findById($id);

        if ($row === null) {
            $output->writeln(sprintf('<error>DLQ entry "%s" not found.</error>', $id));
            return Command::FAILURE;
        }

        $statusDisplay = match ($row['status'] ?? 'failed') {
            'failed'   => '<error>failed</>',
            'replayed' => '<info>replayed</>',
            default    => $row['status'],
        };

        $output->writeln(sprintf('<info>DLQ entry %s</info>', $row['id']));
        $output->writeln('');
        $output->writeln(sprintf('  <fg=gray>Status:</>          %s', $statusDisplay));
        $output->writeln(sprintf('  <fg=gray>Transport:</>       %s', $row['transport_name'] ?? '—'));
        $output->writeln(sprintf('  <fg=gray>Event:</>           %s', $row['event_class'] ?? '—'));
        $output->writeln(sprintf('  <fg=gray>Handler:</>         %s', $row['handler_id'] ?? '—'));
        $output->writeln(sprintf('  <fg=gray>Exception:</>       <fg=yellow>%s</>', $row['exception_class'] ?? '—'));
        $output->writeln(sprintf('  <fg=gray>Attempts:</>        %s', $row['attempt_count'] ?? '—'));
        $output->writeln(sprintf('  <fg=gray>Failed At:</>       %s', $row['failed_at'] ?? '—'));

        if (!empty($row['replayed_at'])) {
            $output->writeln(sprintf('  <fg=gray>Replayed At:</>     %s', $row['replayed_at']));
        }

        $output->writeln('');
        $output->writeln(sprintf(
            '  <fg=gray>Failure Reason:</> <error>%s</error>',
            $row['failure_reason'] ?? 'unknown',
        ));

        $headers = [];
        if (!empty($row['headers'])) {
            $headers = is_string($row['headers'])
                ? (json_decode($row['headers'], true) ?? [])
                : $row['headers'];
        }

        if (!empty($headers)) {
            $output->writeln('');
            $output->writeln('  <fg=gray>Headers:</>');
            foreach ($headers as $k => $v) {
                $output->writeln(sprintf('    <fg=gray>%s:</> %s', $k, $v));
            }
        }

        $output->writeln('');
        $output->writeln('  <fg=gray>Payload:</>');
        $formatted = json_encode(json_decode($row['payload'] ?? '{}', true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        foreach (explode("\n", $formatted ?: ($row['payload'] ?? '')) as $line) {
            $output->writeln('    ' . $line);
        }

        $output->writeln('');
        $output->writeln('<fg=gray>To replay: vortos:dlq:replay --id=' . $row['id'] . '</>');

        return Command::SUCCESS;
    }
}
