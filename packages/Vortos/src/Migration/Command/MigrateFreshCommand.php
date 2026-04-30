<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\MigratorConfiguration;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;
use Vortos\Migration\Service\DependencyFactoryProvider;

/**
 * Drops all database tables and re-runs all migrations from scratch.
 *
 * This is a development-time convenience command equivalent to Laravel's
 * migrate:fresh. It gives you a guaranteed clean state when the database
 * has drifted during development or feature branching.
 *
 * ## Usage
 *
 *   php bin/console vortos:migrate:fresh
 *   php bin/console vortos:migrate:fresh --no-interaction   # skip confirmation
 *
 * ## Hard restrictions
 *
 * Refuses to run in the 'prod' environment. Requires either interactive
 * confirmation or --no-interaction. Designed for local dev and CI pipelines
 * that create a fresh DB per run.
 *
 * ## Implementation note
 *
 * Uses PostgreSQL-specific DROP TABLE ... CASCADE syntax. Tables are dropped
 * individually rather than DROP SCHEMA CASCADE to preserve schema-level
 * permissions and custom types.
 */
#[AsCommand(
    name: 'vortos:migrate:fresh',
    description: 'Drop all tables and re-run all migrations from scratch (non-production only)',
)]
final class MigrateFreshCommand extends Command
{
    public function __construct(
        private readonly DependencyFactoryProvider $factoryProvider,
        private readonly Connection $connection,
        private readonly string $env,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->env === 'prod') {
            $output->writeln('<error>ERROR: vortos:migrate:fresh cannot run in the production environment.</error>');
            $output->writeln('<error>       Use vortos:migrate for production deployments.</error>');
            return Command::FAILURE;
        }

        $force = (bool) $input->getOption('force');

        if (!$force && $input->isInteractive()) {
            /** @var QuestionHelper $helper */
            $helper = $this->getHelper('question');
            $question = new ConfirmationQuestion(
                sprintf(
                    '<question>This will DROP ALL TABLES in the "%s" environment. Continue? [y/N]</question> ',
                    $this->env,
                ),
                false,
            );

            if (!$helper->ask($input, $output, $question)) {
                $output->writeln('<comment>Aborted.</comment>');
                return Command::SUCCESS;
            }
        }

        $this->dropAllTables($output);

        $factory = $this->factoryProvider->create();
        $storage = $factory->getMetadataStorage();
        $storage->ensureInitialized();

        $targetVersion = $factory->getVersionAliasResolver()->resolveVersionAlias('latest');
        $plan          = $factory->getMigrationPlanCalculator()->getPlanUntilVersion($targetVersion);

        if (count($plan) === 0) {
            $output->writeln('<comment>No migrations found to apply.</comment>');
            return Command::SUCCESS;
        }

        $output->writeln(sprintf('<info>Running %d migration(s)...</info>', count($plan)));

        $factory->getMigrator()->migrate(
            $plan,
            (new MigratorConfiguration())->setAllOrNothing(true),
        );

        $output->writeln(sprintf(
            '<info>✔ Fresh migration complete: %d migration(s) applied.</info>',
            count($plan),
        ));

        return Command::SUCCESS;
    }

    private function dropAllTables(OutputInterface $output): void
    {
        $schemaManager = $this->connection->createSchemaManager();
        $tableNames    = $schemaManager->listTableNames();

        if (empty($tableNames)) {
            $output->writeln('<comment>No tables to drop.</comment>');
            return;
        }

        $output->writeln(sprintf('<comment>Dropping %d table(s)...</comment>', count($tableNames)));

        foreach ($tableNames as $tableName) {
            $this->connection->executeStatement(
                sprintf('DROP TABLE IF EXISTS %s CASCADE', $this->connection->quoteIdentifier($tableName)),
            );
        }

        $output->writeln('<info>✔ All tables dropped.</info>');
        $output->writeln('');
    }
}
