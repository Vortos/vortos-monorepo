<?php

declare(strict_types=1);

namespace Vortos\AnalyticsPosthog\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use Vortos\AnalyticsPosthog\FlagSync\PosthogFlagSynchronizer;

/**
 * Mirrors every Vortos flag definition into PostHog.
 *
 * The event listener keeps the mirror current as flags change; this is the reconciliation
 * backstop — it repairs anything a failed call skipped, and is what you run once after
 * turning the mirror on to backfill flags that already existed. Safe to run repeatedly:
 * flags that already match are left alone.
 *
 * Writes its result to output rather than the log, because the production log channel drops
 * `info()` and an ops command whose outcome is invisible is not an ops command.
 */
#[AsCommand(
    name: 'vortos:flags:posthog:sync',
    description: 'Mirror Vortos feature flag definitions into PostHog so they appear in its UI',
)]
final class FlagsPosthogSyncCommand extends Command
{
    public function __construct(private readonly PosthogFlagSynchronizer $synchronizer)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report what would change without writing to PostHog')
            ->addOption('flag', null, InputOption::VALUE_REQUIRED, 'Mirror a single flag by name instead of all of them')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $json   = (bool) $input->getOption('json');
        $flag   = $input->getOption('flag');

        if (!$this->synchronizer->isConfigured()) {
            $message = 'PostHog flag mirroring is not configured. Set POSTHOG_PROJECT_ID and '
                . 'POSTHOG_PERSONAL_API_KEY (a personal API key with the `feature_flag:write` scope — '
                . 'the project ingestion key will not work for the management API).';

            if ($json) {
                $output->writeln((string) json_encode(['configured' => false, 'error' => $message]));
            } else {
                $output->writeln('');
                $output->writeln(' <fg=yellow>' . $message . '</>');
                $output->writeln('');
            }

            return Command::FAILURE;
        }

        try {
            $report = is_string($flag) && $flag !== ''
                ? $this->synchronizer->syncOne($flag, $dryRun)
                : $this->synchronizer->syncAll($dryRun);
        } catch (Throwable $e) {
            if ($json) {
                $output->writeln((string) json_encode(['configured' => true, 'error' => $e->getMessage()]));
            } else {
                $output->writeln('');
                $output->writeln(' <fg=red>PostHog flag sync failed:</> ' . $e->getMessage());
                $output->writeln('');
            }

            return Command::FAILURE;
        }

        if ($json) {
            $output->writeln((string) json_encode([
                'configured' => true,
                'dry_run'    => $report->dryRun,
                'created'    => $report->created,
                'updated'    => $report->updated,
                'unchanged'  => $report->unchanged,
                'failed'     => $report->failed,
            ], JSON_PRETTY_PRINT));

            return $report->hasFailures() ? Command::FAILURE : Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln(sprintf(
            ' <fg=white;options=bold>PostHog flag mirror</> <fg=gray>(%d flag%s%s)</>',
            $report->total(),
            $report->total() === 1 ? '' : 's',
            $report->dryRun ? ', dry run' : '',
        ));
        $output->writeln('');

        $this->section($output, 'created',   $report->created,   'green');
        $this->section($output, 'updated',   $report->updated,   'cyan');
        $this->section($output, 'unchanged', $report->unchanged, 'gray');

        foreach ($report->failed as $name => $reason) {
            $output->writeln(sprintf('   <fg=red>failed</>    %s <fg=gray>— %s</>', $name, $reason));
        }

        $output->writeln('');

        if ($report->total() === 0) {
            $output->writeln(' <fg=yellow>No flags to mirror.</>');
            $output->writeln('');
        }

        return $report->hasFailures() ? Command::FAILURE : Command::SUCCESS;
    }

    /** @param list<string> $names */
    private function section(OutputInterface $output, string $label, array $names, string $colour): void
    {
        foreach ($names as $name) {
            $output->writeln(sprintf('   <fg=%s>%-9s</> %s', $colour, $label, $name));
        }
    }
}
