<?php

declare(strict_types=1);

namespace Vortos\Backup\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Backup\Domain\BackupKind;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Drill\DrillRunner;
use Vortos\Backup\Environment\DefaultEnvironment;

#[AsCommand(name: BackupDrillCommand::NAME, description: 'Run a restore drill (provision → restore → invariants → teardown).')]
final class BackupDrillCommand extends Command
{
    public const NAME = 'backup:drill';

    /**
     * Taken from here by {@see \Vortos\Backup\Schedule\CronFragmentGenerator} rather than written
     * out by hand, so a generated crontab cannot drift from the option it is trying to pass. The
     * emitted WAL shipper and base-backup scripts both once invoked names that had never existed and
     * failed silently for weeks; the rule that came out of it is that generated artifacts derive
     * every command and option name from the class that defines it.
     */
    public const OPTION_PITR = 'pitr';

    public const OPTION_KIND = 'kind';


    public function __construct(private readonly ?DrillRunner $runner)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('engine', null, InputOption::VALUE_REQUIRED, 'Database engine: postgres|mongo', 'postgres')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', DefaultEnvironment::NAME)
            ->addOption('shallow', null, InputOption::VALUE_NONE, 'Shallow decrypt-verify only (no full restore)')
            // Named for the outcome rather than for the artifact, because that is what an operator
            // reaching for it wants to prove: not "drill a physical_base" but "show me that we can
            // recover to the last archived minute".
            ->addOption(
                self::OPTION_PITR,
                null,
                InputOption::VALUE_NONE,
                'Drill the point-in-time path: restore the newest physical base backup and replay '
                . 'archived WAL on top of it to the end of the archive',
            )
            // The general form of --pitr, and the reason it exists: without it there is no way to
            // ask for the LOGICAL path either. Unqualified, the runner takes the newest restorable
            // artifact — which, once base backups are drillable, is whichever kind happens to be
            // newer. That is fine for an ad-hoc "prove something restores", and useless for
            // reproducing what a specific schedule does.
            ->addOption(
                self::OPTION_KIND,
                null,
                InputOption::VALUE_REQUIRED,
                'Restrict the drill to one artifact kind: logical_full|physical_base (default: the '
                . 'newest restorable artifact of any kind)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->runner === null) {
            $output->writeln('<error>Restore drills are not configured. Set VORTOS_BACKUP_DRILL_DSN '
                . 'to an ephemeral-database endpoint to enable backup:drill, then re-run.</error>');

            return self::FAILURE;
        }

        $engine = DatabaseEngine::fromString((string) $input->getOption('engine'));
        $env = (string) $input->getOption('env');
        $shallow = (bool) $input->getOption('shallow');
        $pitr = (bool) $input->getOption(self::OPTION_PITR);
        $kindOption = $input->getOption(self::OPTION_KIND);

        if ($pitr && \is_string($kindOption) && $kindOption !== BackupKind::PhysicalBase->value) {
            $output->writeln(sprintf(
                '<error>--pitr and --kind=%s contradict each other: --pitr IS --kind=%s.</error>',
                $kindOption,
                BackupKind::PhysicalBase->value,
            ));

            return self::FAILURE;
        }

        $onlyKind = match (true) {
            $pitr => BackupKind::PhysicalBase,
            \is_string($kindOption) && $kindOption !== '' => BackupKind::tryFrom($kindOption),
            default => null,
        };

        if (\is_string($kindOption) && $kindOption !== '' && $onlyKind === null) {
            $output->writeln(sprintf('<error>Unknown backup kind "%s".</error>', $kindOption));

            return self::FAILURE;
        }

        if ($onlyKind !== null && $shallow) {
            // A shallow drill decrypts the artifact and discards it. Combined with --pitr it would
            // decrypt a base backup, replay nothing, and report a pass — the precise shape of a
            // drill that proves nothing while looking like the strongest one available.
            $output->writeln('<error>--pitr/--kind and --shallow are mutually exclusive: a shallow '
                . 'drill decrypts an artifact and discards it, so it can neither replay WAL nor '
                . 'prove anything about a particular restore path.</error>');

            return self::FAILURE;
        }

        $report = $this->runner->run($engine, $env, $shallow, $onlyKind);

        if ($report->passed()) {
            $output->writeln(sprintf(
                '<info>Drill passed:</info> %s/%s (%s) — RTO %dms, artifact %s',
                $engine->value,
                $env,
                $report->kind->value ?? 'auto',
                $report->rtoMs,
                $report->artifactId,
            ));

            // Printed on success as well as failure. The evidence IS the deliverable of a
            // point-in-time drill — how far the log replayed, and whether it reached the end of the
            // archive — and a bare "passed" invites the reader to assume more than was proved.
            foreach ($report->invariants as $result) {
                $output->writeln(sprintf(
                    '  %s %s: %s',
                    $result->passed ? '<info>✓</info>' : '<error>✗</error>',
                    $result->name,
                    $result->detail,
                ));
            }

            return self::SUCCESS;
        }

        $output->writeln(sprintf(
            '<error>Drill FAILED:</error> %s/%s (%s) — %s',
            $engine->value,
            $env,
            $report->kind->value ?? 'auto',
            $report->error ?? 'invariant failure',
        ));

        foreach ($report->invariants as $result) {
            $status = $result->passed ? '<info>✓</info>' : '<error>✗</error>';
            $output->writeln(sprintf('  %s %s: %s', $status, $result->name, $result->detail));
        }

        return self::FAILURE;
    }
}
