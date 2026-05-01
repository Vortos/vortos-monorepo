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
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. User)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $aggregate = (string) $input->getArgument('aggregate');
        $context   = (string) ($input->getOption('context') ?: $aggregate);

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=User</error>');
            return Command::FAILURE;
        }

        $vars = [
            'Namespace'          => "App\\{$context}",
            'ClassName'          => $aggregate,
            'AggregateNamespace' => "App\\{$context}",
            'AggregateClass'     => $aggregate,
            'TableName'          => $this->toTableName($aggregate),
        ];

        $output->writeln("<info>vortos:make:write-repository</info> {$aggregate} --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Domain/Repository/{$aggregate}RepositoryInterface.php",
            $this->engine->render('repository-interface', $vars),
            $output,
        );
        $this->engine->write(
            "{$context}/Infrastructure/Repository/{$aggregate}Repository.php",
            $this->engine->render('write-repository', $vars),
            $output,
        );

        return Command::SUCCESS;
    }

    private function toTableName(string $name): string
    {
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);
        return $snake . 's';
    }
}
