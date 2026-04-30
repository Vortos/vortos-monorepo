<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Service\DependencyFactoryProvider;
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
    ) {
        parent::__construct();
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

        $output->writeln('<info>Migration Status</info>');
        $output->writeln(str_repeat('─', 72));
        $output->writeln('');

        if ($available->count() === 0) {
            $output->writeln('<comment>No migration classes found in migrations/.</comment>');
            $output->writeln('Run <info>vortos:migrate:publish</info> or <info>vortos:migrate:make</info> to create migrations.');
        } else {
            $table = new Table($output);
            $table->setHeaders(['Version', 'Description', 'Status', 'Executed At']);
            $table->setStyle('box');

            foreach ($available->getItems() as $i => $migration) {
                $version    = (string) $migration->getVersion();
                $isExecuted = $executed->hasMigration($migration->getVersion());
                $executedAt = '';

                if ($isExecuted) {
                    $executedAt = $executed->getMigration($migration->getVersion())
                        ->getExecutedAt()
                        ?->format('Y-m-d H:i:s') ?? '';
                }

                $status = $isExecuted
                    ? '<info>Migrated</info>'
                    : '<comment>Pending</comment>';

                $desc = $migration->getMigration()->getDescription();

                $table->addRow([
                    $version,
                    $desc !== '' ? $desc : '<fg=gray>—</>',
                    $status,
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

    private function warnUnpublishedStubs(OutputInterface $output): void
    {
        $manifest    = $this->loadManifest();
        $stubs       = $this->scanner->scan();
        $unpublished = array_filter($stubs, static fn(array $s) => !isset($manifest[$s['relative']]));

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
