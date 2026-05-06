<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Schema\MigrationDriftReport;
use Vortos\Migration\Service\MigrationDriftDetector;
use Vortos\Migration\Service\MigrationDriftFormatter;
use Vortos\Migration\Service\DependencyFactoryProvider;
use Vortos\Migration\Service\ModuleMigrationRegistry;
use Vortos\Migration\Service\ModuleSchemaProviderScanner;
use Vortos\Migration\Service\ModuleStubScanner;

/**
 * Displays migration status: which are run, which are pending.
 *
 * Also checks for unpublished module SQL stubs and warns the developer
 * to run vortos:migrate:publish before migrating.
 *
 * ## Usage
 *
 *   php bin/console vortos:migrate:status
 *
 * ## Output columns
 *
 *   Version     — timestamped class name (e.g. Version20260430000001)
 *   Description — from getDescription() on the migration class
 *   Status      — Migrated / Pending
 *   Executed At — UTC timestamp of when the migration ran
 */
#[AsCommand(
    name: 'vortos:migrate:status',
    description: 'Show database migration status',
)]
final class MigrateStatusCommand extends Command
{
    private const MANIFEST_FILE = 'migrations/.vortos-published.json';

    public function __construct(
        private readonly DependencyFactoryProvider $factoryProvider,
        private readonly ModuleStubScanner $scanner,
        private readonly string $projectDir,
        private readonly ?ModuleMigrationRegistry $moduleRegistry = null,
        private readonly ?MigrationDriftDetector $driftDetector = null,
        private readonly ?MigrationDriftFormatter $driftFormatter = null,
        private readonly ?ModuleSchemaProviderScanner $schemaScanner = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output machine-readable JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factory = $this->factoryProvider->create();
        $storage = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $available = $factory->getMigrationPlanCalculator()->getMigrations();
        $executed  = $storage->getExecutedMigrations();
        $new       = $factory->getMigrationStatusCalculator()->getNewMigrations();
        $orphaned  = $factory->getMigrationStatusCalculator()->getExecutedUnavailableMigrations();
        $asJson    = (bool) $input->getOption('json');
        $rows      = [];

        if ($asJson) {
            foreach ($available->getItems() as $migration) {
                $version = (string) $migration->getVersion();
                $isExecuted = $executed->hasMigration($migration->getVersion());
                $report = $this->driftReportFor($version, $isExecuted);

                $rows[] = [
                    'version' => $version,
                    'description' => $migration->getMigration()->getDescription(),
                    'status' => $isExecuted ? 'migrated' : 'pending',
                    'executed_at' => $isExecuted
                        ? $executed->getMigration($migration->getVersion())->getExecutedAt()?->format(\DateTimeInterface::ATOM)
                        : null,
                    'schema' => ($this->driftFormatter ?? new MigrationDriftFormatter())->toArray($report, $isExecuted),
                ];
            }

            $output->writeln(json_encode([
                'migrated' => $executed->count(),
                'pending' => $new->count(),
                'orphaned' => $orphaned->count(),
                'migrations' => $rows,
                'unpublished_stubs' => $this->unpublishedStubs(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return Command::SUCCESS;
        }

        $output->writeln('<info>Migration Status</info>');
        $output->writeln(str_repeat('─', 72));
        $output->writeln('');

        if ($available->count() === 0) {
            $output->writeln('<comment>No migration classes found in migrations/.</comment>');
            $output->writeln('Run <info>vortos:migrate:publish</info> or <info>vortos:migrate:make</info> to create migrations.');
        } else {
            $table = new Table($output);
            $table->setHeaders(['Version', 'Description', 'Status', 'Schema', 'Executed At']);
            $table->setStyle('box');

            foreach ($available->getItems() as $i => $migration) {
                $version    = (string) $migration->getVersion();
                $isExecuted = $executed->hasMigration($migration->getVersion());
                $report     = $this->driftReportFor($version, $isExecuted);
                $executedAt = '';

                if ($isExecuted) {
                    $executedAt = $executed->getMigration($migration->getVersion())
                        ->getExecutedAt()
                        ?->format('Y-m-d H:i:s') ?? '';
                }

                $status = $isExecuted
                    ? '<info>Migrated</info>'
                    : '<comment>Pending</comment>';
                $schema = $this->schemaLabel($report, $isExecuted);

                $desc = $migration->getMigration()->getDescription();

                $table->addRow([
                    $version,
                    $desc !== '' ? $desc : '<fg=gray>—</>',
                    $status,
                    $schema,
                    $executedAt !== '' ? $executedAt : '<fg=gray>—</>',
                ]);

                if ($i < $available->count() - 1) {
                    $table->addRow(new TableSeparator());
                }
            }

            $table->render();
            $output->writeln('');

            $pending  = $new->count();
            $migrated = $executed->count();

            $output->writeln(sprintf(
                '  <info>%d migrated</info> · <comment>%d pending</comment>%s',
                $migrated,
                $pending,
                $orphaned->count() > 0
                    ? sprintf(' · <error>%d orphaned (executed but no matching class)</error>', $orphaned->count())
                    : '',
            ));
            $output->writeln('');
        }

        $this->warnUnpublishedStubs($output);

        return Command::SUCCESS;
    }

    private function driftReportFor(string $version, bool $isExecuted): ?MigrationDriftReport
    {
        if ($isExecuted || $this->moduleRegistry === null || $this->driftDetector === null) {
            return null;
        }

        $descriptor = $this->moduleRegistry->descriptorForClass($version);

        return $descriptor !== null ? $this->driftDetector->detect($descriptor) : null;
    }

    private function schemaLabel(?MigrationDriftReport $report, bool $isExecuted): string
    {
        $label = ($this->driftFormatter ?? new MigrationDriftFormatter())->label($report, $isExecuted);

        return match ($report?->status()) {
            MigrationDriftReport::Clean => '<info>' . $label . '</info>',
            MigrationDriftReport::CompatibleExisting,
            MigrationDriftReport::Partial => '<error>' . $label . '</error>',
            MigrationDriftReport::Unknown => '<comment>' . $label . '</comment>',
            default => $isExecuted ? '<info>' . $label . '</info>' : '<fg=gray>' . $label . '</>',
        };
    }

    private function warnUnpublishedStubs(OutputInterface $output): void
    {
        $unpublished = $this->unpublishedStubs();

        if (empty($unpublished)) {
            return;
        }

        $output->writeln(sprintf(
            '  <comment>! %d unpublished module migration stub(s) detected:</comment>',
            count($unpublished),
        ));

        foreach ($unpublished as $stub) {
            $output->writeln(sprintf(
                '  !   <comment>%s</comment>/<fg=white>%s</>',
                $stub['module'],
                $stub['filename'],
            ));
        }

        $output->writeln('');
        $output->writeln('  ! Run <info>php bin/console vortos:migrate:publish</info> to generate migration classes for these stubs.');
        $output->writeln('');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function unpublishedStubs(): array
    {
        $manifest = $this->loadManifest();
        $stubs = [];
        $schemaCounterparts = [];

        foreach ($this->schemaScanner?->scan() ?? [] as $schemaProvider) {
            $stubs[] = $schemaProvider;
            $schemaCounterparts[$this->replaceExtension($schemaProvider['relative'], 'sql')] = true;
        }

        foreach ($this->scanner->scan() as $sqlStub) {
            if (isset($schemaCounterparts[$sqlStub['relative']])) {
                continue;
            }

            $stubs[] = $sqlStub;
        }

        return array_values(array_filter($stubs, fn(array $s) => !$this->isPublished($s['relative'], $manifest)));
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function isPublished(string $relative, array $manifest): bool
    {
        if (isset($manifest[$relative])) {
            return true;
        }

        return isset($manifest[$this->replaceExtension($relative, 'sql')])
            || isset($manifest[$this->replaceExtension($relative, 'php')]);
    }

    private function replaceExtension(string $path, string $extension): string
    {
        return preg_replace('/\.[^.\/]+$/', '.' . $extension, $path) ?? $path;
    }

    /** @return array<string, mixed> */
    private function loadManifest(): array
    {
        $path = $this->projectDir . '/' . self::MANIFEST_FILE;

        if (!file_exists($path)) {
            return [];
        }

        $data = json_decode((string) file_get_contents($path), true);

        return $data['published'] ?? [];
    }
}
