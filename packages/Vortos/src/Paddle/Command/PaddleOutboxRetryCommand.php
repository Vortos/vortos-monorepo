<?php

declare(strict_types=1);

namespace Vortos\Paddle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Paddle\Outbox\PaddleOutboxRetryStoreInterface;

#[AsCommand(
    name: 'vortos:paddle:outbox:retry',
    description: 'Reset permanently failed Paddle outbox entries back to pending for re-delivery.',
)]
final class PaddleOutboxRetryCommand extends Command
{
    public function __construct(private readonly PaddleOutboxRetryStoreInterface $store)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('id',        null, InputOption::VALUE_REQUIRED, 'Retry a single entry by ID.')
            ->addOption('operation', null, InputOption::VALUE_REQUIRED, 'Filter by operation name (e.g. customer.create).')
            ->addOption('limit',     'l',  InputOption::VALUE_REQUIRED, 'Maximum entries to reset.', 100)
            ->addOption('dry-run',   null, InputOption::VALUE_NONE,     'List matching failed entries without resetting them.')
            ->addOption('force',     null, InputOption::VALUE_NONE,     'Skip the confirmation prompt.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io        = new SymfonyStyle($input, $output);
        $dryRun    = (bool) $input->getOption('dry-run');
        $force     = (bool) $input->getOption('force');
        $id        = $input->getOption('id') !== null ? (int) $input->getOption('id') : null;
        $operation = $input->getOption('operation') ?: null;
        $limit     = max(1, min((int) $input->getOption('limit'), 10000));

        $count = $this->store->countFailed($id, $operation);

        if ($count === 0) {
            $io->success('No failed Paddle outbox entries match the given filters.');
            return Command::SUCCESS;
        }

        if ($dryRun) {
            $io->note(sprintf('Dry run — %d failed entr%s match (not resetting).', $count, $count === 1 ? 'y' : 'ies'));
            $rows = $this->store->listFailed($limit, $id, $operation);
            $this->renderTable($output, $rows);
            return Command::SUCCESS;
        }

        $io->warning(sprintf('%d failed entr%s will be reset to pending.', $count, $count === 1 ? 'y' : 'ies'));

        if (!$force) {
            $helper   = $this->getHelper('question');
            $question = new ConfirmationQuestion('Continue? [y/N] ', false);
            if (!$helper->ask($input, $output, $question)) {
                $io->comment('Aborted.');
                return Command::SUCCESS;
            }
        }

        $reset = $this->store->resetFailed($limit, $id, $operation);
        $io->success(sprintf('Reset %d Paddle outbox entr%s to pending.', $reset, $reset === 1 ? 'y' : 'ies'));

        return Command::SUCCESS;
    }

    private function renderTable(OutputInterface $output, array $rows): void
    {
        $table = new Table($output);
        $table->setHeaders(['ID', 'Operation', 'Attempts', 'Last Error', 'Failed At', 'Created At']);

        foreach ($rows as $row) {
            $table->addRow([
                $row['id'],
                $row['operation'],
                $row['attempts'],
                mb_strimwidth((string) ($row['last_error'] ?? ''), 0, 60, '…'),
                $row['failed_at'] ?? '',
                $row['created_at'],
            ]);
        }

        $table->render();
    }
}
