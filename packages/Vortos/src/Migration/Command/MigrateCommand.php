<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Doctrine\Migrations\MigratorConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Vortos\Migration\Service\DependencyFactoryProvider;
use Vortos\Migration\Service\MigrationDriftFormatter;
use Vortos\Migration\Service\MigrationLock;
use Vortos\Migration\Service\MigrationPreflight;

/**
 * Runs all pending database migrations.
 *
 * ## Usage
 *
 *   php bin/console vortos:migrate
 *   php bin/console vortos:migrate --force           # skip confirmation (required in prod deploys)
 *   php bin/console vortos:migrate --dry-run         # preview SQL without applying
 *
 * ## Behaviour
 *
 * Lists pending migrations before executing. In interactive mode asks for confirmation
 * unless --force is supplied. Uses all-or-nothing transaction semantics: if any migration
 * fails the entire run is rolled back.
 *
 * For CI/CD pipelines pass --force --no-interaction to run unattended.
 */
#[AsCommand(
    name: 'vortos:migrate',
    description: 'Run all pending database migrations',
)]
final class MigrateCommand extends Command
{
    public function __construct(
        private readonly DependencyFactoryProvider $factoryProvider,
        private readonly ?MigrationPreflight $preflight = null,
        private readonly ?MigrationDriftFormatter $driftFormatter = null,
        private readonly ?MigrationLock $lock = null,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview SQL without executing')
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
            $locked = $this->lock->acquire((int) $input->getOption('lock-timeout'));

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

            $output->writeln(sprintf('<info>%d pending migration(s):</info>', count($plan)));

            foreach ($plan->getItems() as $item) {
                $output->writeln(sprintf('  <comment>→</comment> %s', (string) $item->getVersion()));
            }

            $output->writeln('');

            if (!$this->passesPreflight($plan, $output)) {
                return Command::FAILURE;
            }

            if ($dryRun) {
                $output->writeln('<comment>[DRY RUN] No changes applied.</comment>');
                return Command::SUCCESS;
            }

            if (!$force && $input->isInteractive()) {
                /** @var QuestionHelper $helper */
                $helper = $this->getHelper('question');

                if (!$helper->ask($input, $output, new ConfirmationQuestion('<question>Proceed? [y/N]</question> ', false))) {
                    $output->writeln('<comment>Migration aborted.</comment>');
                    return Command::SUCCESS;
                }
            }

            $factory->getMigrator()->migrate(
                $plan,
                (new MigratorConfiguration())->setAllOrNothing(true),
            );

            $output->writeln(sprintf(
                '<info>✔ %d migration(s) executed successfully.</info>',
                count($plan),
            ));

            return Command::SUCCESS;
        } finally {
            if ($locked) {
                $this->lock?->release();
            }
        }
    }

    private function passesPreflight(\Doctrine\Migrations\Metadata\MigrationPlanList $plan, OutputInterface $output): bool
    {
        if ($this->preflight === null) {
            return true;
        }

        $reports = $this->preflight->blockingReportsForPlan($plan);

        if ($reports === []) {
            return true;
        }

        $formatter = $this->driftFormatter ?? new MigrationDriftFormatter();

        $output->writeln('<error>Schema drift detected. No migrations were executed.</error>');
        $output->writeln('');

        foreach ($reports as $version => $report) {
            $descriptor = $report->descriptor();
            $output->writeln(sprintf(
                '  <comment>→</comment> %s <fg=gray>(%s/%s)</>',
                $version,
                $descriptor?->module() ?? 'Unknown',
                $descriptor?->filename() ?? 'unknown',
            ));
            $output->writeln('    ' . $formatter->label($report, executed: false));

            foreach ($report->existingTables() as $table) {
                $output->writeln(sprintf('    existing table: <info>%s</info>', $table));
            }
            foreach ($report->missingTables() as $table) {
                $output->writeln(sprintf('    missing table: <comment>%s</comment>', $table));
            }
            foreach ($report->missingIndexes() as $index) {
                $output->writeln(sprintf('    missing index: <comment>%s</comment>', $index));
            }
        }

        $output->writeln('');
        $output->writeln('If the existing schema is correct, run:');
        $output->writeln('  <info>php vortos migrate:adopt VERSION --verify</info>');

        return false;
    }
}
