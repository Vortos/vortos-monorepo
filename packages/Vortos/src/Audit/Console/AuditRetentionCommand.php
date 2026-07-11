<?php

declare(strict_types=1);

namespace Vortos\Audit\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Audit\Retention\AuditRetentionSweeper;

/**
 * Runs the archive-then-purge retention sweep. Intended to be scheduled (e.g. via
 * vortos-scheduler) daily. Refuses to run when no durable archive target is configured —
 * retention must never delete un-archived audit data.
 *
 *   php bin/console vortos:audit:retention --dry-run
 */
#[AsCommand(name: 'vortos:audit:retention', description: 'Archive aged audit records to cold storage and purge them from the hot table.')]
final class AuditRetentionCommand extends Command
{
    public function __construct(private readonly ?AuditRetentionSweeper $sweeper) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would be archived+purged without modifying anything.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if ($this->sweeper === null) {
            $io->error('No durable archive target is configured (vortos-object-store not installed / no bucket). Refusing to purge audit data.');
            return Command::FAILURE;
        }

        $dryRun = (bool) $input->getOption('dry-run');
        $result = $this->sweeper->sweep($dryRun);

        $rows = [];
        foreach ($result->perChain() as $chainKey => $count) {
            $rows[] = [$chainKey, number_format($count)];
        }

        $io->title($dryRun ? 'Audit Retention — DRY RUN' : 'Audit Retention');
        if ($rows === []) {
            $io->success('Nothing past its retention window. No action taken.');
            return Command::SUCCESS;
        }

        $io->table(['Chain', $dryRun ? 'Would archive+purge' : 'Archived+purged'], $rows);
        $io->success(sprintf('%s: %s records across %d chains.', $dryRun ? 'Would process' : 'Processed', number_format($result->total()), count($rows)));

        return Command::SUCCESS;
    }
}
