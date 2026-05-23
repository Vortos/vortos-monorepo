<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Doctrine\Migrations\MigratorConfiguration;
use Doctrine\Migrations\Version\Direction;
use Doctrine\Migrations\Version\ExecutionResult;
use Doctrine\Migrations\Version\Version;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Vortos\Migration\Schema\MigrationPlanItemAnalysis;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;
use Vortos\Migration\Service\MigrationLock;
use Vortos\Migration\Service\MigrationPlanAnalyzer;

/**
 * Runs all pending database migrations with smart conflict detection.
 *
 * Before executing, every pending migration is analysed against the live schema:
 *
 *   →  will run          — DB objects don't exist yet (or all guarded by IF NOT EXISTS)
 *   ⊘  will be adopted   — objects already exist and schema matches; SQL is skipped,
 *                          migration is marked executed in the tracking table
 *   ⚠  BLOCKED (manual)  — tables exist but columns are missing; add them manually,
 *                          then re-run. Exact columns are printed.
 *   ⚠  BLOCKED (partial) — some objects exist, some don't; inspect with migrate:status.
 *   →  cannot analyse    — SQL could not be extracted; migration runs and DB handles it.
 *
 * Auto-adoption never drops or modifies data. It only inserts a row in the
 * migration tracking table to record the migration as already done.
 */
#[AsCommand(
    name: 'vortos:migrate',
    description: 'Run all pending database migrations',
)]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly DependencyFactoryProviderInterface $factoryProvider,
        private readonly ?MigrationPlanAnalyzer $planAnalyzer = null,
        private readonly ?MigrationLock $lock = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview analysis without executing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt (required for production deploys)')
            ->addOption('lock-timeout', null, InputOption::VALUE_REQUIRED, 'Seconds to wait for migration advisory lock', '60')
            ->addOption('no-lock', null, InputOption::VALUE_NONE, 'Do not acquire the migration advisory lock');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factory = $this->factoryProvider->create();
        $storage = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $dryRun = (bool) $input->getOption('dry-run');
        $force  = (bool) $input->getOption('force');
        $locked = false;

        if (!(bool) $input->getOption('no-lock') && $this->lock !== null) {
            $locked = $this->lock->acquire(max(0, min((int) $input->getOption('lock-timeout'), 3600)));

            if (!$locked) {
                $output->writeln('<error>Another migration process is already running. Could not acquire migration lock.</error>');
                return Command::FAILURE;
            }
        }

        try {
            $targetVersion = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
            $plan          = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($targetVersion);

            if (count($plan) === 0) {
                $output->writeln('<info>Nothing to migrate. Database is up to date.</info>');
                return Command::SUCCESS;
            }

            // Analyse every pending migration against the live schema
            $analysis = $this->planAnalyzer !== null
                ? $this->planAnalyzer->analyze($plan)
                : [];

            // Print per-migration status
            $output->writeln(sprintf('<info>%d pending migration(s):</info>', count($plan)));

            $hasBlockers = false;

            foreach ($plan->getItems() as $item) {
                $version  = (string) $item->getVersion();
                $itemData = $analysis[$version] ?? null;

                $output->writeln('  ' . $this->formatItem($version, $itemData));

                if ($itemData === null || !$itemData->isBlocker()) {
                    continue;
                }

                $hasBlockers = true;

                if ($itemData->status() === MigrationPlanItemAnalysis::NeedsColumns) {
                    foreach ($itemData->missingColumns() as $table => $columns) {
                        $output->writeln(sprintf(
                            '      <comment>Add to %s:</comment> %s',
                            $table,
                            implode(', ', $columns),
                        ));
                    }
                }

                if ($itemData->status() === MigrationPlanItemAnalysis::Partial) {
                    if ($itemData->existingTables() !== []) {
                        $output->writeln(sprintf(
                            '      <comment>Existing tables:</comment> %s',
                            implode(', ', $itemData->existingTables()),
                        ));
                    }
                    if ($itemData->missingTables() !== []) {
                        $output->writeln(sprintf(
                            '      <comment>Missing tables:</comment> %s',
                            implode(', ', $itemData->missingTables()),
                        ));
                    }
                }
            }

            $output->writeln('');

            if ($hasBlockers) {
                $output->writeln('<error>Migration blocked — resolve the issues above before running.</error>');
                $output->writeln('If the existing schema is already correct, run:');
                $output->writeln('  <info>php vortos migrate:adopt --all-compatible</info>');
                return Command::FAILURE;
            }

            if ($dryRun) {
                $output->writeln('<comment>[DRY RUN] No changes applied.</comment>');
                return Command::SUCCESS;
            }

            // Collect adoptable migrations
            $adoptedVersions = [];
            foreach ($analysis as $version => $itemData) {
                if ($itemData->shouldAutoAdopt()) {
                    $adoptedVersions[] = $version;
                }
            }

            // Single confirmation prompt covering both adoption and execution
            if (!$force && $input->isInteractive()) {
                /** @var QuestionHelper $helper */
                $helper = $this->getHelper('question');

                if (!$helper->ask($input, $output, new ConfirmationQuestion('<question>Proceed? [y/N]</question> ', false))) {
                    $output->writeln('<comment>Migration aborted.</comment>');
                    return Command::SUCCESS;
                }
            }

            // Auto-adopt compatible migrations (mark as executed, skip SQL)
            if ($adoptedVersions !== []) {
                $now = new \DateTimeImmutable();

                foreach ($adoptedVersions as $version) {
                    $result = new ExecutionResult(new Version($version), Direction::UP);
                    $result->setExecutedAt($now);
                    $storage->complete($result);

                    $existingTables = $analysis[$version]->existingTables();
                    $tablesLabel = $existingTables !== []
                        ? implode(', ', array_map(fn(string $t) => '"' . $t . '" table', $existingTables))
                        : 'schema';
                    $output->writeln(sprintf(
                        '  <fg=gray>⊘</> %s <fg=gray>(adopted — %s already exists)</>',
                        $version,
                        $tablesLabel,
                    ));
                }

                $output->writeln('');
            }

            // Build filtered plan: only migrations that were not adopted
            $remainingItems = array_values(array_filter(
                $plan->getItems(),
                static fn($item) => !in_array((string) $item->getVersion(), $adoptedVersions, true),
            ));

            if ($remainingItems === []) {
                $output->writeln('<info>✔ All migrations adopted. Database is up to date.</info>');
                return Command::SUCCESS;
            }

            $filteredPlan = new \Doctrine\Migrations\Metadata\MigrationPlanList($remainingItems, Direction::UP);

            $factory->getMigrator()->migrate(
                $filteredPlan,
                (new MigratorConfiguration())->setAllOrNothing(true),
            );

            $output->writeln(sprintf(
                '<info>✔ %d migration(s) executed successfully.</info>',
                count($remainingItems),
            ));

            return Command::SUCCESS;

        } finally {
            if ($locked) {
                $this->lock?->release();
            }
        }
    }

    private function formatItem(string $version, ?MigrationPlanItemAnalysis $analysis): string
    {
        if ($analysis === null) {
            return sprintf('<comment>→</comment> %s', $version);
        }

        return match ($analysis->status()) {
            MigrationPlanItemAnalysis::Safe,
            MigrationPlanItemAnalysis::Clean   => sprintf('<comment>→</comment> %s', $version),

            MigrationPlanItemAnalysis::Adoptable => sprintf(
                '<fg=gray>⊘</> %s  <fg=gray>(will be adopted — "%s" table already exists)</>',
                $version,
                implode('", "', $analysis->existingTables()),
            ),

            MigrationPlanItemAnalysis::NeedsColumns => sprintf(
                '<error>⚠</error> %s  <error>BLOCKED: missing columns (see below)</error>',
                $version,
            ),

            MigrationPlanItemAnalysis::Partial => sprintf(
                '<error>⚠</error> %s  <error>BLOCKED: partial drift (see below)</error>',
                $version,
            ),

            MigrationPlanItemAnalysis::Unknown => sprintf(
                '<comment>→</comment> %s  <fg=gray>(cannot analyse — will run)</>',
                $version,
            ),

            default => sprintf('<comment>→</comment> %s', $version),
        };
    }
}
