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
    name: 'vortos:make:aggregate',
    description: 'Generate an aggregate root, its typed AggregateId, and repository interface',
)]
final class MakeAggregateCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Aggregate root class name (e.g. User)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Bounded context folder (e.g. Training)')
            ->addOption('no-orm', null, InputOption::VALUE_NONE, 'Generate without ORM annotations even if vortos/persistence-orm is installed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name    = (string) $input->getArgument('name');
        $context = (string) ($input->getOption('context') ?: $name);

        $ormDetected = class_exists(\Vortos\PersistenceOrm\Aggregate\AggregateRoot::class);
        $noOrm       = (bool) $input->getOption('no-orm');
        $ormActive   = $ormDetected && !$noOrm;
        $stubName    = $ormActive ? 'aggregate-orm' : 'aggregate';
        $tableName   = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name) . 's';

        $aggregateNamespace  = "App\\{$context}\\Domain\\{$name}";
        $valueObjectNamespace = "App\\{$context}\\Domain\\{$name}\\ValueObject";
        $repositoryNamespace  = "App\\{$context}\\Domain\\{$name}\\Repository";

        $vars = [
            'Namespace' => $aggregateNamespace,
            'ClassName' => $name,
            'TableName' => $tableName,
        ];

        $idVars = [
            'Namespace' => $valueObjectNamespace,
            'ClassName' => $name,
        ];

        $repoVars = [
            'Namespace' => $repositoryNamespace,
            'ClassName' => $name,
        ];

        $output->writeln("<info>vortos:make:aggregate</info> {$name} --context={$context}" . ($ormActive ? ' <fg=cyan>[ORM]</>' : ''));
        $output->writeln('');

        if ($ormDetected && !$noOrm) {
            $output->writeln('<comment>ORM mode:</comment> vortos/persistence-orm detected — generating with Doctrine annotations.');
            $output->writeln('To generate without ORM: add <info>--no-orm</info>');
            $output->writeln('');
        }

        $this->engine->write("{$context}/Domain/{$name}/{$name}.php", $this->engine->render($stubName, $vars), $output);
        $this->engine->write("{$context}/Domain/{$name}/ValueObject/{$name}Id.php", $this->engine->render('entity-id', $idVars), $output);
        $this->engine->write("{$context}/Domain/{$name}/Repository/{$name}RepositoryInterface.php", $this->engine->render('repository-interface', $repoVars), $output);

        $output->writeln('');
        $output->writeln(sprintf(
            'Next: <comment>vortos:make:write-repository %s --context=%s</comment>',
            $name,
            $context,
        ));

        return Command::SUCCESS;
    }
}
