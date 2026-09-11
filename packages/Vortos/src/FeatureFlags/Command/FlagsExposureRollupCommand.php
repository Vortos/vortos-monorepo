<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Exposure\Ledger\ExposureRollup;

/**
 * Folds the raw exposure ledger into its daily rollup and prunes both to retention.
 *
 * Meant to run nightly. Safe to run at any hour and any number of times: the rollup is an
 * upsert and the prune is bounded by absolute dates, so a double-run is a no-op rather than
 * a double-deletion.
 *
 * Output is RETURNED, not logged. In production the application log channel sits at WARNING,
 * so an operational command that reports through the logger reports into a void — the numbers
 * have to come back through stdout to be seen at all.
 */
#[AsCommand(
    name: 'vortos:flags:exposures:rollup',
    description: 'Roll up first-party flag exposures into daily aggregates and prune to retention',
)]
final class FlagsExposureRollupCommand extends Command
{
    public function __construct(
        private readonly ExposureRollup $rollup,
        private readonly int $ledgerRetentionDays,
        private readonly int $rollupRetentionDays,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'no-prune',
                null,
                InputOption::VALUE_NONE,
                'Roll up without deleting anything. Use when investigating; the scheduled run should always prune.',
            )
            ->addOption(
                'ledger-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Override raw ledger retention in days',
            )
            ->addOption(
                'rollup-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Override rollup retention in days',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $ledgerDays = (int) ($input->getOption('ledger-days') ?? $this->ledgerRetentionDays);
        $rollupDays = (int) ($input->getOption('rollup-days') ?? $this->rollupRetentionDays);

        if ($input->getOption('no-prune')) {
            $written = $this->rollup->rollupPending();

            $output->writeln(sprintf('Rolled up %d day(s); pruning skipped.', count($written)));
            foreach ($written as $day => $rows) {
                $output->writeln(sprintf('  %s  %d rollup row(s)', $day, $rows));
            }

            return Command::SUCCESS;
        }

        // prune() rolls everything forward before it deletes anything — see ExposureRollup.
        $deleted = $this->rollup->prune($ledgerDays, $rollupDays);

        $output->writeln(sprintf(
            'Rolled up and pruned. Ledger: %d row(s) deleted beyond %d days. Rollup: %d row(s) deleted beyond %d days.',
            $deleted['ledger'],
            $ledgerDays,
            $deleted['rollup'],
            $rollupDays,
        ));

        return Command::SUCCESS;
    }
}
