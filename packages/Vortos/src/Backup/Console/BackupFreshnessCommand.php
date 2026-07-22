<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Event\BackupEvent;
use Vortos\Backup\Event\BackupEventSinkInterface;
use Vortos\Backup\Health\BackupFreshness;
use Vortos\Backup\Health\BackupFreshnessInspector;

/**
 * Answers "when did a backup last actually succeed?" with an exit code, so the check can be driven by
 * anything that understands a process: an off-host cron, a CI job, a Kubernetes CronJob, a monitoring
 * agent's exec check.
 *
 * Exit code is the contract: 0 = every target fresh, 1 = at least one stale or never-run, 2 = the
 * check itself could not run. Note that 2 is NOT success — a checker that cannot check must not be
 * mistaken for a clean bill of health, which is the failure mode that let the original incident hide.
 *
 * Runs off the catalog, so pointing it at the production database from a different machine is a
 * complete, independent verification of the backup cadence — no agent on the box, no trust in the
 * worker's own account of itself. That independence is the reason this exists alongside
 * {@see \Vortos\Backup\Health\BackupFreshnessProbe}: the probe rides the app's health surface, this
 * runs anywhere.
 *
 * `--emit-events` additionally raises `backup.stale` on the alert sink, for when this is the scheduled
 * owner of the signal rather than an ad-hoc check.
 */
#[AsCommand(
    name: 'backup:freshness',
    description: 'Verify backups are not stale (catalog-derived dead-man check). Exit 1 when stale.',
)]
final class BackupFreshnessCommand extends Command
{
    public function __construct(
        private readonly BackupFreshnessInspector $inspector,
        private readonly ClockInterface $clock,
        private readonly ?BackupEventSinkInterface $events = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON (for monitoring agents)')
            ->addOption('emit-events', null, InputOption::VALUE_NONE, 'Emit backup.stale on the alert sink for each breach');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $results = $this->inspector->inspect();
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Freshness check could not run: %s</error>', $e->getMessage()));

            return 2;
        }

        $breaches = array_values(array_filter($results, static fn (BackupFreshness $f): bool => !$f->isHealthy()));

        if ($input->getOption('emit-events') && $this->events !== null) {
            $now = $this->clock->now();
            foreach ($breaches as $breach) {
                $this->events->emit(BackupEvent::stale(
                    $breach->engine,
                    $breach->environment,
                    $breach->describe(),
                    $now,
                ));
            }
        }

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode([
                'healthy' => $breaches === [],
                'checked_at' => $this->clock->now()->format(DATE_ATOM),
                'targets' => array_map(static fn (BackupFreshness $f): array => $f->toDetail(), $results),
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $breaches === [] ? Command::SUCCESS : Command::FAILURE;
        }

        if ($results === []) {
            $output->writeln('<comment>No backup schedules declared — nothing to check.</comment>');

            return Command::SUCCESS;
        }

        foreach ($results as $result) {
            $output->writeln(sprintf(
                '  %s %s',
                $result->isHealthy() ? '<info>✓</info>' : '<error>✗</error>',
                $result->describe(),
            ));
        }

        if ($breaches !== []) {
            $output->writeln(sprintf('<error>%d backup target(s) stale.</error>', count($breaches)));

            return Command::FAILURE;
        }

        $output->writeln('<info>All backup targets are fresh.</info>');

        return Command::SUCCESS;
    }
}
