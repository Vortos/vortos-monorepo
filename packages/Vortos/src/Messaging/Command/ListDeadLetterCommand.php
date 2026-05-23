<?php

declare(strict_types=1);

namespace Vortos\Messaging\Command;

use InvalidArgumentException;
use Vortos\Messaging\DeadLetter\DeadLetterRepositoryInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'vortos:dlq:list',
    description: 'List failed consumer messages in the dead letter queue',
)]
final class ListDeadLetterCommand extends Command
{
    public function __construct(
        private readonly DeadLetterRepositoryInterface $repository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('transport', null, InputOption::VALUE_OPTIONAL, 'Filter by transport name')
            ->addOption('event-class', null, InputOption::VALUE_OPTIONAL, 'Filter by event class (FQCN)')
            ->addOption('handler', null, InputOption::VALUE_OPTIONAL, 'Filter by handler ID')
            ->addOption('limit', 'l', InputOption::VALUE_OPTIONAL, 'Max rows to show', 50)
            ->addOption('latest', null, InputOption::VALUE_NONE, 'Show most recently failed first')
            ->addOption('failed-from', null, InputOption::VALUE_OPTIONAL, 'Filter by failed_at >= timestamp')
            ->addOption('failed-to', null, InputOption::VALUE_OPTIONAL, 'Filter by failed_at <= timestamp');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $transport  = $input->getOption('transport') ?: null;
        $eventClass = $input->getOption('event-class') ?: null;
        $handler    = $input->getOption('handler') ?: null;
        $limit      = max(1, min((int) $input->getOption('limit'), 10000));
        $latest     = (bool) $input->getOption('latest');

        try {
            $range = ReplayTimestampRange::fromOptions(
                $input->getOption('failed-from') ?: null,
                $input->getOption('failed-to') ?: null,
                '--failed-from',
                '--failed-to',
            );
        } catch (InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::INVALID;
        }

        $rows = $this->repository->fetchFailed($limit, $transport, $eventClass, null, $latest, $range->from, $range->to);

        if ($handler !== null) {
            $rows = array_values(array_filter($rows, fn(array $r) => $r['handler_id'] === $handler));
        }

        if (empty($rows)) {
            $output->writeln('<info>No failed messages in the DLQ.</info>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Found %d failed message(s).</info>', count($rows)));
        $output->writeln('');
        $output->writeln(sprintf(
            '  <fg=gray>%-36s  %-42s  %-30s  %8s  %s</>',
            'ID', 'Handler', 'Event', 'Attempts', 'Failed',
        ));
        $output->writeln('  <fg=gray>' . str_repeat('─', 130) . '</>');

        foreach ($rows as $row) {
            $shortName = $this->shortName($row['event_class'] ?? '');
            $age       = $this->age($row['failed_at'] ?? '');

            $output->writeln(sprintf(
                '  <fg=cyan>%s</>  %-42s  %-30s  %8s  %s',
                $row['id'],
                $this->truncate($row['handler_id'] ?? '', 42),
                $this->truncate($shortName, 30),
                $row['attempt_count'] ?? '?',
                $age,
            ));
        }

        $output->writeln('');
        $output->writeln('<fg=gray>Tip: vortos:dlq:show <id> for full details · vortos:dlq:replay to re-produce to Kafka</>');

        return Command::SUCCESS;
    }

    private function shortName(string $fqcn): string
    {
        return substr(strrchr($fqcn, '\\') ?: $fqcn, 1) ?: $fqcn;
    }

    private function truncate(string $s, int $max): string
    {
        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1) . '…' : str_pad($s, $max);
    }

    private function age(string $timestamp): string
    {
        if ($timestamp === '') return '—';

        try {
            $diff = (new \DateTimeImmutable())->getTimestamp() - (new \DateTimeImmutable($timestamp))->getTimestamp();
        } catch (\Throwable) {
            return $timestamp;
        }

        if ($diff < 60) return $diff . 's ago';
        if ($diff < 3600) return round($diff / 60) . 'min ago';
        if ($diff < 86400) return round($diff / 3600) . 'h ago';
        return round($diff / 86400) . 'd ago';
    }
}
