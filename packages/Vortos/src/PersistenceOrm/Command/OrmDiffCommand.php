<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Command;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Migration\Generator\MigrationClassGenerator;
use Vortos\Migration\Service\DependencyFactoryProvider;

#[AsCommand(
    name: 'vortos:orm:diff',
    description: 'Generate a migration from ORM entity diff against the current database schema',
)]
final class OrmDiffCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly DependencyFactoryProvider $factoryProvider,
        private readonly MigrationClassGenerator $generator,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tool  = new SchemaTool($this->em);
        $metas = $this->em->getMetadataFactory()->getAllMetadata();
        $sqls  = $tool->getUpdateSchemaSql($metas);

        if (empty($sqls)) {
            $output->writeln('<info>Schema is up to date — nothing to generate.</info>');
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

        file_put_contents($filePath, $content);

        $output->writeln(sprintf('<info>✔ Migration created:</info> migrations/%s.php', $shortName));
        $output->writeln(sprintf('  <comment>%d</comment> SQL statement(s). Run <info>vortos:migrate</info> to apply.', count($sqls)));

        return Command::SUCCESS;
    }
}
