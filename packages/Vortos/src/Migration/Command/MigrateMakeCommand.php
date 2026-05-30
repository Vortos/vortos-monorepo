<?php

declare(strict_types=1);

namespace Vortos\Migration\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;

/**
 * Generates an empty Doctrine migration class ready to be filled in.
 *
 * ## Usage
 *
 *   php bin/console vortos:migrate:make CreateOrdersTable
 *   php bin/console vortos:migrate:make AddIndexToUsersEmail
 *   php bin/console vortos:migrate:make users --aggregate
 *
 * ## Output
 *
 * Creates migrations/VersionYYYYMMDDHHIISS.php with:
 *   - getDescription() returning the human-readable form of the given name
 *   - Empty up() and down() stubs with guidance comments
 *
 * With --aggregate, the name is used as the table name and up() is pre-filled
 * with a CREATE TABLE containing id (VARCHAR 36 PK) and lock_version (INTEGER).
 *
 * The class name is always timestamp-based (Doctrine convention) so migrations
 * sort chronologically regardless of what name was given.
 */
#[AsCommand(
    name: 'vortos:migrate:make',
    description: 'Generate an empty migration class',
)]
final class MigrateMakeCommand extends Command
{
    public function __construct(
        private readonly DependencyFactoryProviderInterface $factoryProvider,
        private readonly MigrationClassGenerator $generator,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument(
            'name',
            InputArgument::OPTIONAL,
            'Short description for the migration, or table name when --aggregate is used (CamelCase or snake_case)',
            '',
        );

        $this->addOption(
            'aggregate',
            null,
            InputOption::VALUE_NONE,
            'Pre-fill up() with a CREATE TABLE scaffold containing id and lock_version columns',
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $factory = $this->factoryProvider->create();

        $dirs = $factory->getConfiguration()->getMigrationDirectories();

        if (empty($dirs)) {
            $output->writeln('<error>No migration directories configured in migrations.php.</error>');
            return Command::FAILURE;
        }

        $namespace = (string) array_key_first($dirs);
        $className = $factory->getClassNameGenerator()->generateClassName($namespace);

        // Strip the namespace prefix from the FQCN to get just the class name
        $shortName = substr($className, strlen($namespace) + 1);

        $rawName   = (string) $input->getArgument('name');
        $aggregate = (bool) $input->getOption('aggregate');

        if ($aggregate) {
            if ($rawName === '') {
                $output->writeln('<error>Provide a table name when using --aggregate. Example: vortos:migrate:make users --aggregate</error>');
                return Command::FAILURE;
            }
            $content = $this->generator->generateAggregate($shortName, $namespace, $rawName);
        } else {
            $description = $rawName !== '' ? $this->humanize($rawName) : '';
            $content     = $this->generator->generateEmpty($shortName, $namespace, $description);
        }

        $dir      = rtrim((string) array_values($dirs)[0], '/');
        $filePath = $dir . '/' . $shortName . '.php';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filePath, $content, LOCK_EX);

        $relative = ltrim(str_replace($this->projectDir, '', $filePath), '/');

        $output->writeln(sprintf('<info>✔ Migration created:</info> %s', $relative));

        if ($aggregate) {
            $output->writeln(sprintf(
                '  Add your domain columns to <comment>up()</comment>, then run <info>vortos:migrate</info>.',
            ));
        } else {
            $output->writeln(sprintf(
                '  Fill in <comment>up()</comment> and <comment>down()</comment>, then run <info>vortos:migrate</info>.',
            ));
        }

        return Command::SUCCESS;
    }

    private function humanize(string $name): string
    {
        // CamelCase → "Camel Case"
        $spaced = preg_replace('/([a-z])([A-Z])/', '$1 $2', $name) ?? $name;
        // snake_case / kebab-case → spaces
        $spaced = str_replace(['_', '-'], ' ', $spaced);

        return ucfirst(strtolower($spaced));
    }
}
