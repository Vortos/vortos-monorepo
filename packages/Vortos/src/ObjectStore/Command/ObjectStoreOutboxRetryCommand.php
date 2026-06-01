<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\ObjectStore\Outbox\ObjectStoreOutboxRetryStoreInterface;

#[AsCommand(
    name: 'vortos:object-store:outbox:retry',
    description: 'Reset dead-lettered object-store outbox entries back to pending for re-delivery.',
)]
final class ObjectStoreOutboxRetryCommand extends Command
{
    public function __construct(private readonly ObjectStoreOutboxRetryStoreInterface $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id',           null, InputOption::VALUE_REQUIRED, 'Retry a single entry by UUID.')
            ->addOption('operation',    null, InputOption::VALUE_REQUIRED, 'Filter by operation name (put, delete, copy, move, promote).')
            ->addOption('created-from', null, InputOption::VALUE_REQUIRED, 'Filter entries created at or after this ISO 8601 timestamp.')
            ->addOption('created-to',   null, InputOption::VALUE_REQUIRED, 'Filter entries created at or before this ISO 8601 timestamp.')
            ->addOption('limit',        'l',  InputOption::VALUE_REQUIRED, 'Maximum entries to reset.', 100)
            ->addOption('dry-run',      null, InputOption::VALUE_NONE,     'List matching dead entries without resetting them.')
            ->addOption('force',        null, InputOption::VALUE_NONE,     'Skip the confirmation prompt.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io          = new SymfonyStyle($input, $output);
        $dryRun      = (bool) $input->getOption('dry-run');
        $force       = (bool) $input->getOption('force');
        $id          = $input->getOption('id') ?: null;
        $operation   = $input->getOption('operation') ?: null;
        $limit       = max(1, min((int) $input->getOption('limit'), 10000));
        $createdFrom = $this->parseDate($input->getOption('created-from'), $output);
        $createdTo   = $this->parseDate($input->getOption('created-to'), $output);

        if ($createdFrom === false || $createdTo === false) {
            return Command::INVALID;
        }

        $count = $this->store->countDead($id, $operation, $createdFrom, $createdTo);

        if ($count === 0) {
            $io->success('No dead-lettered object-store outbox entries match the given filters.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run — %d dead entr%s match (not resetting).', $count, $count === 1 ? 'y' : 'ies'));
            $rows = $this->store->listDead($limit, $id, $operation, $createdFrom, $createdTo);
            $this->renderTable($output, $rows);
            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d dead entr%s will be reset to pending.', $count, $count === 1 ? 'y' : 'ies'));

        if (!$force) {
            $helper   = $this->getHelper('question');
            $question = new ConfirmationQuestion('Continue? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                $io->comment('Aborted.');
                return Command::SUCCESS;
            }
        }

        $reset = $this->store->resetDead($limit, $id, $operation, $createdFrom, $createdTo);
        $io->success(sprintf('Reset %d object-store outbox entr%s to pending.', $reset, $reset === 1 ? 'y' : 'ies'));

        return Command::SUCCESS;
    }

    private function parseDate(?string $value, OutputInterface $output): \DateTimeImmutable|false|null
    {
        if ($value === null) {
            return null;
        }

        $dt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value)
            ?: \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        if ($dt === false) {
            $output->writeln(sprintf('<error>Invalid date format "%s". Use ISO 8601 (e.g. 2026-06-01T00:00:00+00:00).</error>', $value));
            return false;
        }

        return $dt;
    }

    private function renderTable(OutputInterface $output, array $rows): void
    {
        $table = new Table($output);
        $table->setHeaders(['ID', 'Operation', 'Attempts', 'Last Error', 'Processed At', 'Created At']);

        foreach ($rows as $row) {
            $table->addRow([
                $row['id'],
                $row['operation'],
                $row['attempt_count'],
                mb_strimwidth((string) ($row['last_error'] ?? ''), 0, 60, '…'),
                $row['processed_at'] ?? '',
                $row['created_at'],
            ]);
        }

        $table->render();
    }
}
