<?php

declare(strict_types=1);

namespace Vortos\Migration\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Driver\PgNative\PgTargetStatsReader;
use Vortos\Migration\Safety\MigrationArtifactFactoryInterface;
use Vortos\Migration\Safety\MigrationSafetyAnalyzerInterface;
use Vortos\Migration\Safety\PendingMigrationVersionProviderInterface;
use Vortos\Migration\Safety\SafetyResult;
use Vortos\Migration\Safety\Severity;

#[AsCommand(
    name: 'vortos:migrate:analyze',
    description: 'Analyze pending migrations for lock-safety hazards (CI gate)',
)]
final class MigrateAnalyzeCommand extends Command
{
    public function __construct(
        private readonly MigrationSafetyAnalyzerInterface $analyzer,
        private readonly MigrationArtifactFactoryInterface $artifactFactory,
        private readonly PendingMigrationVersionProviderInterface $versionProvider,
        private readonly ?PgTargetStatsReader $statsReader = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Analyze all migrations, not just pending')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'Read table stats from target for data-driven analysis (on by default when a database is reachable)')
            ->addOption('no-target', null, InputOption::VALUE_NONE, 'Analyze without reading table statistics, even if a database is reachable')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output results as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $json = (bool) $input->getOption('json');
        $analyzeAll = (bool) $input->getOption('all');
        // Statistics are read BY DEFAULT whenever a reader is available.
        //
        // This used to require an explicit --target, which quietly inverted the gate's meaning: the
        // size-sensitive rules fail CLOSED when no statistics are present, so the default invocation
        // reported every ADD COLUMN ... DEFAULT as touching a hot table — including nine-row tables
        // — while a live database sat one query away. An operator who sees that failure has two
        // options, and the one the message suggested was to annotate the migration with an opt-out
        // attribute, permanently disabling the check for the cases where it is real.
        //
        // --no-target keeps the offline behaviour for CI runs that have no database to reach. The
        // fail-closed posture is unchanged; it is now the fallback when statistics cannot be read
        // rather than the default when they can.
        $target = null;
        if (!(bool) $input->getOption('no-target') && $this->statsReader !== null) {
            $target = $this->statsReader->read();
        }

        $versions = $analyzeAll
            ? $this->versionProvider->getAll()
            : $this->versionProvider->getPending();

        $allResults = [];
        $totalErrors = 0;
        $totalWarnings = 0;

        foreach ($versions as $version) {
            $artifact = $this->artifactFactory->fromClass($version);
            $result = $this->analyzer->analyze($artifact, $target);

            foreach ($result->diagnostics as $d) {
                if ($d->severity === Severity::Error) {
                    $totalErrors++;
                } elseif ($d->severity === Severity::Warning) {
                    $totalWarnings++;
                }
            }

            $allResults[] = ['version' => $version, 'result' => $result];
        }

        if ($json) {
            $output->writeln(json_encode([
                'ok' => $totalErrors === 0,
                'engine' => $this->analyzer->engine(),
                'analyzed' => count($allResults),
                'errors' => $totalErrors,
                'warnings' => $totalWarnings,
                'migrations' => array_map(
                    static fn (array $entry) => [
                        'version' => $entry['version'],
                        ...$entry['result']->toArray(),
                    ],
                    $allResults,
                ),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $totalErrors === 0 ? Command::SUCCESS : Command::FAILURE;
        }

        $analyzed = count($allResults);

        if ($analyzed === 0) {
            $output->writeln('<comment>No migrations to analyze.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('Analyzing %d migration(s) with engine "%s"…', $analyzed, $this->analyzer->engine()));
        $output->writeln('');

        foreach ($allResults as $entry) {
            /** @var SafetyResult $result */
            $result = $entry['result'];
            $version = $entry['version'];

            if ($result->diagnostics === []) {
                $output->writeln(sprintf('  <info>✔</info> %s', $this->shortVersion($version)));
                continue;
            }

            $output->writeln(sprintf('  <error>✘</error> %s', $this->shortVersion($version)));

            foreach ($result->diagnostics as $d) {
                $icon = $d->severity === Severity::Error ? '<error>ERR</error>' : '<comment>WRN</comment>';
                $output->writeln(sprintf('      %s [%s] %s', $icon, $d->ruleId, $d->message));

                if ($d->table !== null) {
                    $output->writeln(sprintf('          table: %s', $d->table));
                }

                $output->writeln(sprintf('          fix: %s', $d->remediation));
            }
        }

        $output->writeln('');

        if ($totalErrors > 0) {
            $output->writeln(sprintf(
                '<error>%d error(s), %d warning(s) in %d migration(s). Fix errors to pass the CI gate.</error>',
                $totalErrors,
                $totalWarnings,
                $analyzed,
            ));
            return Command::FAILURE;
        }

        if ($totalWarnings > 0) {
            $output->writeln(sprintf(
                '<comment>%d warning(s) in %d migration(s). No blocking errors.</comment>',
                $totalWarnings,
                $analyzed,
            ));
        } else {
            $output->writeln(sprintf('<info>All %d migration(s) passed safety analysis.</info>', $analyzed));
        }

        return Command::SUCCESS;
    }

    private function shortVersion(string $version): string
    {
        $parts = explode('\\', $version);

        return end($parts);
    }
}
