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
    name: 'vortos:make:write-repository',
    description: 'Generate a PostgreSQL write repository for an aggregate',
)]
final class MakeWriteRepositoryCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('aggregate', InputArgument::REQUIRED, 'Aggregate class name (e.g. User)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Bounded context folder (e.g. User)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $aggregate = (string) $input->getArgument('aggregate');
        $context   = (string) ($input->getOption('context') ?: $aggregate);

        $ormActive = class_exists(\Vortos\PersistenceOrm\Aggregate\AggregateRoot::class);
        $stubName  = $ormActive ? 'write-repository-orm' : 'write-repository';

        $vars = [
            'Namespace'                => "App\\{$context}\\Infrastructure\\Repository",
            'AggregateEntityNamespace' => "App\\{$context}\\Domain\\{$aggregate}",
            'AggregateRepositoryNamespace' => "App\\{$context}\\Domain\\{$aggregate}\\Repository",
            'AggregateClass'           => $aggregate,
            'TableName'                => $this->toTableName($aggregate),
        ];

        $repoInterfaceVars = [
            'Namespace' => "App\\{$context}\\Domain\\{$aggregate}\\Repository",
            'ClassName' => $aggregate,
        ];

        $output->writeln("<info>vortos:make:write-repository</info> {$aggregate} --context={$context}" . ($ormActive ? ' <fg=cyan>[ORM]</>' : ''));
        $output->writeln('');

        $this->engine->write(
            "{$context}/Domain/{$aggregate}/Repository/{$aggregate}RepositoryInterface.php",
            $this->engine->render('repository-interface', $repoInterfaceVars),
            $output,
        );
        $this->engine->write(
            "{$context}/Infrastructure/Repository/{$aggregate}Repository.php",
            $this->engine->render($stubName, $vars),
            $output,
        );

        $output->writeln('');
        $output->writeln(sprintf(
            'Next: bind <info>%sRepositoryInterface</info> → <info>%sRepository</info> in your DI config',
            $aggregate,
            $aggregate,
        ));

        return Command::SUCCESS;
    }

    private function toTableName(string $name): string
    {
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);
        return $snake . 's';
    }
}
