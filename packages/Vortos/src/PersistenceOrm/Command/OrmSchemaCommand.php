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

#[AsCommand(
    name: 'vortos:orm:schema',
    description: 'Generate or apply the ORM database schema',
)]
final class OrmSchemaCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dump-sql', null, InputOption::VALUE_NONE, 'Print the SQL statements without executing')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Apply the SQL to the database')
            ->addOption('drop', null, InputOption::VALUE_NONE, 'Drop all tables instead of create/update (use with --force)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $tool      = new SchemaTool($this->em);
        $metas     = $this->em->getMetadataFactory()->getAllMetadata();
        $dumpSql   = $input->getOption('dump-sql');
        $force     = $input->getOption('force');
        $drop      = $input->getOption('drop');

        if (!$dumpSql && !$force) {
            $output->writeln('<fg=yellow>Pass --dump-sql to preview SQL or --force to apply it.</>');
            return Command::SUCCESS;
        }

        if ($drop) {
            $sqls = $tool->getDropSchemaSQL($metas);
            $verb = 'drop';
        } else {
            $sqls = $tool->getUpdateSchemaSql($metas);
            $verb = 'update';
        }

        if (empty($sqls)) {
            $output->writeln('<fg=green>Schema is up to date — nothing to do.</>');
            return Command::SUCCESS;
        }

        if ($dumpSql) {
            $output->writeln('');
            foreach ($sqls as $sql) {
                $output->writeln($sql . ';');
            }
            $output->writeln('');
            return Command::SUCCESS;
        }

        // --force: apply
        $output->writeln(sprintf('<fg=yellow>Applying schema %s (%d statements)...</>', $verb, count($sqls)));

        if ($drop) {
            $tool->dropSchema($metas);
        } else {
            $tool->updateSchema($metas, saveMode: true);
        }

        $output->writeln('<fg=green>Done.</>');

        return Command::SUCCESS;
    }
}
