<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\Migration\Service\MigrationDriftDetectorInterface;
use Vortos\Migration\Service\ModuleMigrationRegistryInterface;

/**
 * CI-friendly command that checks all executed framework migrations against the
 * live database schema and exits non-zero if any drift is detected.
 *
 * Only checks framework module migrations — user-authored migrations are skipped
 * because Vortos has no schema definition for them.
 *
 * Exit codes:
 *   0 — all executed module migrations are clean (schema matches)
 *   1 — one or more migrations have drift (tables or columns missing)
 */
#[AsCommand(
    name: 'vortos:migrate:verify',
    description: 'Verify executed framework migrations match the live database schema (CI-friendly)',
)]
final class MigrateVerifyCommand extends Command
{
    public function __construct(
        private readonly DependencyFactoryProviderInterface $factoryProvider,
        private readonly ModuleMigrationRegistryInterface $moduleRegistry,
        private readonly MigrationDriftDetectorInterface $driftDetector,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output results as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factory  = $this->factoryProvider->create();
        $executed = $factory->getMetadataStorage()->getExecutedMigrations();
        $json     = (bool) $input->getOption('json');

        $results = [];
        $drifted = 0;
        $checked = 0;

        foreach ($executed->getItems() as $migration) {
            $version = (string) $migration->getVersion();
            $descriptor = $this->moduleRegistry->descriptorForClass($version);

            if ($descriptor === null) {
                $results[] = ['version' => $version, 'status' => 'skipped', 'reason' => 'user migration'];
                continue;
            }

            $report = $this->driftDetector->detect($descriptor);
            $status = $report->status();
            $checked++;

            if ($status === MigrationDriftReport::CompatibleExisting) {
                $results[] = ['version' => $version, 'status' => 'ok'];
            } else {
                $drifted++;
                $results[] = [
                    'version'        => $version,
                    'status'         => 'drift',
                    'drift_status'   => $status,
                    'missing_tables' => $report->missingTables(),
                    'missing_indexes'=> $report->missingIndexes(),
                    'missing_columns'=> $report->missingColumns(),
                ];
            }
        }

        if ($json) {
            $output->writeln(json_encode([
                'ok'      => $drifted === 0,
                'checked' => $checked,
                'drifted' => $drifted,
                'results' => $results,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $drifted === 0 ? Command::SUCCESS : Command::FAILURE;
        }

        if ($checked === 0) {
            $output->writeln('<comment>No executed framework migrations to verify.</comment>');
            return Command::SUCCESS;
        }

        foreach ($results as $row) {
            if ($row['status'] === 'skipped') {
                continue;
            }

            if ($row['status'] === 'ok') {
                $output->writeln(sprintf('  <info>✔</info> %s', $row['version']));
            } else {
                $output->writeln(sprintf('  <error>✘</error> %s  <comment>[%s]</comment>', $row['version'], $row['drift_status']));

                foreach ($row['missing_tables'] as $table) {
                    $output->writeln(sprintf('      missing table:  %s', $table));
                }
                foreach ($row['missing_columns'] as $table => $cols) {
                    $output->writeln(sprintf('      missing columns on %s: %s', $table, implode(', ', $cols)));
                }
                foreach ($row['missing_indexes'] as $index) {
                    $output->writeln(sprintf('      missing index:  %s', $index));
                }
            }
        }

        $output->writeln('');

        if ($drifted > 0) {
            $output->writeln(sprintf(
                '<error>Schema drift detected in %d migration(s). Run vortos:migrate to fix or investigate manually.</error>',
                $drifted,
            ));
            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>All %d executed migration(s) verified — schema is clean.</info>', $checked));
        return Command::SUCCESS;
    }
}
