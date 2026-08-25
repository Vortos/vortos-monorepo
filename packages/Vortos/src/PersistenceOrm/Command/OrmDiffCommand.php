<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Service\DependencyFactoryProviderInterface;

/**
 * The difference between what the entities describe and what the database actually is.
 *
 * Two jobs, because they are the same question asked for two reasons. By default it writes
 * the migration that closes the gap. With `--check` it writes nothing and fails when a gap
 * exists, which is what CI needs.
 *
 * ## Why --check is worth having
 *
 * A migration can apply perfectly and still build a table the mapping cannot read: a
 * missing column, a wrong type, an absent index. Nothing else catches that. A test suite
 * on in-memory doubles has no schema; static analysis cannot see the join between a
 * mapping and a table; and {@see \Vortos\Migration\Command\MigrateVerifyCommand} checks
 * only framework module migrations, because Vortos holds no schema definition for
 * application ones. So the first thing to notice is the query failing in production.
 *
 * ## Why DROP is still filtered under --check
 *
 * A database legitimately holds tables no entity maps — framework bookkeeping, another
 * service's, something retired but not yet dropped. Failing on those would make the gate
 * cry wolf until somebody turned it off. What is left is the set of things the mapping
 * NEEDS and the schema lacks, and every one of those is a query that will fail.
 */
#[AsCommand(
    name: 'vortos:orm:diff',
    description: 'Generate a migration from ORM entity diff, or with --check fail when the schema does not match the mapping',
)]
final class OrmDiffCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DependencyFactoryProviderInterface $factoryProvider,
        private readonly MigrationClassGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dry-run', null, InputOption::VALUE_NONE, 'Print the SQL diff without writing a migration file');
        $this->addOption('check', null, InputOption::VALUE_NONE, 'Write nothing and exit non-zero if the schema does not match the mapping (for CI)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        $check  = (bool) $input->getOption('check');

        $tool  = new SchemaTool($this->em);
        $metas = $this->em->getMetadataFactory()->getAllMetadata();

        // Never pass on nothing. A run that mapped no entities is a broken check reporting
        // success, which is worse than no check at all — and under --check it is the whole
        // point of the command.
        if ($check && $metas === []) {
            $output->writeln('<error>No mapped entities were found — this check cannot mean anything.</error>');

            return Command::FAILURE;
        }
        $sqls = array_values(array_filter(
            $tool->getUpdateSchemaSql($metas),
            static fn(string $sql) => !preg_match('/^\s*DROP\s/i', $sql),
        ));

        if (empty($sqls)) {
            $output->writeln($check
                ? sprintf('<info>OK: the schema matches all %d mapped entities.</info>', count($metas))
                : '<info>Schema is up to date — nothing to generate.</info>');

            return Command::SUCCESS;
        }

        if ($check) {
            $output->writeln('<error>The schema does not match the mapping.</error>');
            $output->writeln('');

            foreach ($sqls as $sql) {
                $output->writeln('  ' . $sql . ';');
            }

            $output->writeln('');
            $output->writeln('<comment>Each line is a query the application will run and the database will refuse.</comment>');
            $output->writeln('<comment>Add a migration for it — vortos:orm:diff without --check writes one.</comment>');

            return Command::FAILURE;
        }

        if ($dryRun) {
            $output->writeln(sprintf('<comment>Dry run — %d SQL statement(s), no file written:</comment>', count($sqls)));
            $output->writeln('');
            foreach ($sqls as $sql) {
                $output->writeln('  ' . $sql . ';');
            }
            return Command::SUCCESS;
        }

        $factory = $this->factoryProvider->create();
        $dirs    = $factory->getConfiguration()->getMigrationDirectories();

        if (empty($dirs)) {
            $output->writeln('<error>No migration directories configured in migrations.php.</error>');
            return Command::FAILURE;
        }

        $namespace = (string) array_key_first($dirs);
        $className = $factory->getClassNameGenerator()->generateClassName($namespace);
        $shortName = substr($className, strlen($namespace) + 1);

        $content  = $this->generator->generateFromSql(
            $shortName,
            $namespace,
            'ORM schema diff',
            implode(";\n", $sqls),
        );

        $dir      = rtrim((string) array_values($dirs)[0], '/');
        $filePath = $dir . '/' . $shortName . '.php';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filePath, $content, LOCK_EX);

        $output->writeln(sprintf('<info>✔ Migration created:</info> migrations/%s.php', $shortName));
        $output->writeln(sprintf('  <comment>%d</comment> SQL statement(s). Run <info>vortos:migrate</info> to apply.', count($sqls)));

        return Command::SUCCESS;
    }
}
