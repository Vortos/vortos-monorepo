<?php

declare(strict_types=1);

namespace Vortos\Make\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Make\Engine\GeneratorEngine;

#[AsCommand(
    name: 'vortos:make:entity',
    description: 'Generate an entity, its typed AggregateId, and repository interface',
)]
final class MakeEntityCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Entity class name (e.g. User)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. User)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name    = (string) $input->getArgument('name');
        $context = (string) ($input->getOption('context') ?: $name);

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=User</error>');
            return Command::FAILURE;
        }

        $ormActive = class_exists(\Vortos\PersistenceOrm\Aggregate\OrmAggregateRoot::class);
        $stubName  = $ormActive ? 'entity-orm' : 'entity';
        $tableName = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name) . 's';

        $vars = [
            'Namespace' => "App\\{$context}",
            'ClassName' => $name,
            'TableName' => $tableName,
        ];

        $output->writeln("<info>vortos:make:entity</info> {$name} --context={$context}" . ($ormActive ? ' <fg=cyan>[ORM]</>' : ''));
        $output->writeln('');

        $this->engine->write("{$context}/Domain/Entity/{$name}.php", $this->engine->render($stubName, $vars), $output);
        $this->engine->write("{$context}/Domain/Entity/{$name}Id.php", $this->engine->render('entity-id', $vars), $output);
        $this->engine->write("{$context}/Domain/Repository/{$name}RepositoryInterface.php", $this->engine->render('repository-interface', $vars), $output);

        $output->writeln('');
        $output->writeln(sprintf(
            'Next: <comment>vortos:make:write-repository %s --context=%s</comment>',
            $name,
            $context,
        ));

        return Command::SUCCESS;
    }
}
