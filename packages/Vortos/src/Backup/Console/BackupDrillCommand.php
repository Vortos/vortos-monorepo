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

#[AsCommand(name: 'backup:drill', description: 'Run a restore drill (provision → restore → invariants → teardown).')]
final class BackupDrillCommand extends Command
{
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
                'pitr',
                null,
                InputOption::VALUE_NONE,
                'Drill the point-in-time path: restore the newest physical base backup and replay '
                . 'archived WAL on top of it to the end of the archive',
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
        $onlyKind = (bool) $input->getOption('pitr') ? BackupKind::PhysicalBase : null;

        if ($onlyKind !== null && $shallow) {
            // A shallow drill decrypts the artifact and discards it. Combined with --pitr it would
            // decrypt a base backup, replay nothing, and report a pass — the precise shape of a
            // drill that proves nothing while looking like the strongest one available.
            $output->writeln('<error>--pitr and --shallow are mutually exclusive: a shallow drill '
                . 'never replays WAL, so it cannot verify point-in-time recovery.</error>');

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
