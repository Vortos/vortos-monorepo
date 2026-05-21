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
    name: 'vortos:make:read-repository',
    description: 'Generate a MongoDB read repository and its read model DTO for an aggregate',
)]
final class MakeReadRepositoryCommand extends Command
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
            'Namespace'      => "App\\{$context}",
            'AggregateClass' => $aggregate,
            'CollectionName' => $this->toCollectionName($aggregate),
        ];

        $output->writeln("<info>vortos:make:read-repository</info> {$aggregate} --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Application/ReadModel/{$aggregate}ReadModel.php",
            $this->engine->render('read-model', $vars),
            $output,
        );

        $this->engine->write(
            "{$context}/Infrastructure/Persistence/Mongo/{$aggregate}ReadRepository.php",
            $this->engine->render('read-repository', $vars),
            $output,
        );

        $output->writeln('');
        $output->writeln('<comment>Next steps:</comment>');
        $output->writeln("  1. Add fields to <info>{$aggregate}ReadModel</info>");
        $output->writeln("  2. Map them in <info>{$aggregate}ReadRepository::fromDocument()</info>");
        $output->writeln("  3. Add <info>#[MongoIndex]</info> attributes for your query patterns");
        $output->writeln("  4. Run <info>php bin/console vortos:mongo:sync</info> to apply indexes");

        return Command::SUCCESS;
    }

    private function toCollectionName(string $name): string
    {
        $snake = strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $name) ?? $name);
        return $snake . 's';
    }
}
