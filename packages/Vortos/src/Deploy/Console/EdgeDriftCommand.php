<?php

declare(strict_types=1);

namespace Vortos\Deploy\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Deploy\Cutover\CutoverEventRecorderInterface;
use Vortos\Deploy\Cutover\Drift\EdgeDriftDetector;
use Vortos\Deploy\Cutover\State\EdgeStateStoreInterface;

/**
 * Detects and (optionally) alerts on edge drift — the live edge diverging from the recorded routing
 * intent (a manual admin push, a stale boot file, an adapt-version skew).
 *
 * Detection is read-only. Alerting flows through the {@see CutoverEventRecorderInterface} (the same
 * seam a real cutover records to), so whatever the application wired for cutover audit/alerts also
 * fires for drift — no second alerting stack. Exits non-zero on drift so it can gate a scheduled job
 * or a CI monitor. Safe to run on a schedule (e.g. via vortos-scheduler) as the standing drift guard.
 */
#[AsCommand(
    name: 'deploy:edge:drift',
    description: 'Detect edge drift (live route vs recorded intent); optionally record/alert on it.',
)]
final class EdgeDriftCommand extends Command
{
    public function __construct(
        private readonly EdgeDriftDetector $detector,
        private readonly EdgeStateStoreInterface $stateStore,
        private readonly CutoverEventRecorderInterface $eventRecorder,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment name', 'production')
            ->addOption('alert', null, InputOption::VALUE_NONE, 'Record the drift through the cutover event recorder (audit/alert)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = (string) $input->getOption('env');
        $report = $this->detector->detect($env);

        if (!$report->hasState) {
            $output->writeln(sprintf('<comment>%s</comment>', $report->summary()));

            return self::SUCCESS;
        }

        if ($report->inSync) {
            $output->writeln(sprintf('<info>%s</info>', $report->summary()));

            return self::SUCCESS;
        }

        $output->writeln(sprintf('<error>%s</error>', $report->summary()));

        if ($input->getOption('alert') === true) {
            $state = $this->stateStore->load($env);
            if ($state !== null) {
                // recordDrift routes through the app's configured recorder (Block 16 audit ledger +
                // alerts). The live route is intentionally not reconstructed here — the report reasons
                // already describe the mismatch, secret-free.
                $this->eventRecorder->recordDrift($state->toDesiredRoute(), null);
                $output->writeln('<comment>drift recorded via the cutover event recorder</comment>');
            }
        }

        return self::FAILURE;
    }
}
