<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use Vortos\Messaging\Contract\OutboxPollerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vortos:outbox:list',
    description: 'Inspect outbox rows by status — pending, published, or failed',
)]
final class ListOutboxCommand extends Command
{
    public function __construct(
        private readonly OutboxPollerInterface $poller,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('status', 's', InputOption::VALUE_OPTIONAL, 'Filter by status: pending, published, failed (default: all)')
            ->addOption('transport', null, InputOption::VALUE_OPTIONAL, 'Filter by transport name')
            ->addOption('event-class', null, InputOption::VALUE_OPTIONAL, 'Filter by payload type (FQCN)')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max rows to show', 50)
            ->addOption('latest', null, InputOption::VALUE_NONE, 'Show most recently created first');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $status    = $input->getOption('status') ?: null;
        $transport = $input->getOption('transport') ?: null;
        $event     = $input->getOption('event-class') ?: null;
        $limit     = max(1, min((int) $input->getOption('limit'), 10000));
        $latest    = (bool) $input->getOption('latest');

        if ($status !== null && !in_array($status, ['pending', 'published', 'failed'], true)) {
            $output->writeln('<error>Invalid --status value. Use: pending, published, or failed.</error>');
            return Command::INVALID;
        }

        $rows = $this->poller->query($status, $transport, $event, $limit, $latest);

        if (empty($rows)) {
            $output->writeln('<info>No outbox rows found.</info>');
            return Command::SUCCESS;
        }

        $label = $status ? " ({$status})" : '';
        $output->writeln(sprintf('<info>Found %d outbox row(s)%s.</info>', count($rows), $label));
        $output->writeln('');

        foreach ($rows as $row) {
            $statusDisplay = match ($row->status) {
                'pending'   => '<fg=yellow>pending</>',
                'published' => '<info>published</>',
                'failed'    => '<error>failed</>',
                default     => $row->status,
            };

            $shortName = $this->shortName($row->payloadType);
            $age       = $this->age($row->createdAt);

            $output->writeln(sprintf(
                '  %s  <fg=cyan>%s</>  →  %s  <fg=gray>%s  agg: %s  attempts: %d</>',
                $statusDisplay,
                $shortName,
                $row->transportName,
                $age,
                substr($row->aggregateId, 0, 8) . '...',
                $row->attemptCount,
            ));
            $output->writeln(sprintf('         <fg=gray>id: %s</>', $row->id));

            if ($row->status === 'failed' && $row->failureReason !== null) {
                $output->writeln(sprintf('         <fg=gray>reason: %s</>', mb_substr($row->failureReason, 0, 120)));
            }

            $output->writeln('');
        }

        $output->writeln(sprintf(
            '<fg=gray>Tip: vortos:outbox:show <id> for full row details · vortos:outbox:replay to reset failed rows</>',
        ));

        return Command::SUCCESS;
    }

    private function shortName(string $fqcn): string
    {
        return substr(strrchr($fqcn, '\\') ?: $fqcn, 1) ?: $fqcn;
    }

    private function age(\DateTimeImmutable $dt): string
    {
        $diff = (new \DateTimeImmutable())->getTimestamp() - $dt->getTimestamp();

        if ($diff < 60) return $diff . 's ago';
        if ($diff < 3600) return round($diff / 60) . 'min ago';
        if ($diff < 86400) return round($diff / 3600) . 'h ago';
        return round($diff / 86400) . 'd ago';
    }
}
