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
 * ## Why --check fails only on what is MISSING
 *
 * The first version of this gate failed on the whole diff and was unusable: run against a
 * real application it reported a hundred and fourteen statements, every one of them
 * cosmetic — foreign keys the migrations added and the mapping never declared, defaults
 * the application does not read, indexes whose only fault is not being named the way
 * Doctrine would have named them. A gate that fires on all of that blocks every deploy
 * until somebody turns it off, and then it protects nothing.
 *
 * A schema can differ from its mapping in two very different ways. It can lack something
 * the mapping requires — a table, a column — and then the application issues a query the
 * database refuses, every time, from the first request. Or it can carry more than the
 * mapping describes, or describe it differently, which is untidy and almost never fatal.
 *
 * Only the first kind fails. The rest is printed, because it is worth seeing, and ignored,
 * because acting on it would mean rewriting migration history to satisfy a code generator.
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

    /**
     * Whether this statement adds something the mapping needs and the schema lacks.
     *
     * A missing table or column is the only difference that is certainly fatal: the
     * application will issue a query naming it and the database will refuse, on every
     * request, from the first one. Everything else the diff reports — a constraint, a
     * default, an index name, a widened type — is a difference the running application
     * does not notice.
     *
     * ALTER ... ADD CONSTRAINT is excluded deliberately. A missing foreign key does not
     * stop a query; it is a data-integrity choice, and one migrations make on purpose.
     */
    private static function isMissingFromSchema(string $sql): bool
    {
        if (preg_match('/^\s*CREATE\s+TABLE\s/i', $sql) === 1) {
            return true;
        }

        return preg_match('/^\s*ALTER\s+TABLE\s+\S+\s+ADD\s+(?!CONSTRAINT\b)/i', $sql) === 1;
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
            $missing = array_values(array_filter($sqls, self::isMissingFromSchema(...)));

            if ($missing !== []) {
                $output->writeln('<error>The schema is missing something the mapping requires.</error>');
                $output->writeln('');

                foreach ($missing as $sql) {
                    $output->writeln('  ' . $sql . ';');
                }

                $output->writeln('');
                $output->writeln('<comment>Each line is a query the application will run and the database will refuse.</comment>');
                $output->writeln('<comment>Add a migration for it — vortos:orm:diff without --check writes one.</comment>');

                return Command::FAILURE;
            }

            $output->writeln(sprintf(
                '<info>OK: the schema has everything all %d mapped entities require.</info>',
                count($metas),
            ));

            // Printed, not failed. See the class docblock: this is the untidy half of the
            // diff, and acting on it would mean rewriting migration history to satisfy a
            // code generator.
            $output->writeln(sprintf(
                '<comment>%d cosmetic difference(s) ignored — constraints, defaults, index names.</comment>',
                count($sqls),
            ));

            return Command::SUCCESS;
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
