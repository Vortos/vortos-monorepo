<?php

declare(strict_types=1);

namespace Vortos\Make\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Make\Engine\GeneratorEngine;

#[AsCommand(
    name: 'vortos:make:context',
    description: 'Scaffold a complete bounded context directory structure',
)]
final class MakeContextCommand extends Command
{
    private const array DIRECTORIES = [
        'Domain/Shared/ValueObject',
        'Domain/Shared/Error',
        'Domain/Shared/Service',
        'Application/Command',
        'Application/Query',
        'Application/EventHandler',
        'Application/Projection',
        'Application/Policy',
        'Application/ReadModel',
        'Infrastructure/Repository',
        'Infrastructure/Messaging',
        'Infrastructure/Quota',
        'Infrastructure/Persistence/Mongo',
        'Presentation/Http',
    ];

    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Bounded context name (e.g. Order)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');

        $output->writeln("<info>vortos:make:context</info> {$name}");
        $output->writeln('');

        foreach (self::DIRECTORIES as $dir) {
            $this->engine->ensureDirectory("{$name}/{$dir}", $output);
        }

        $output->writeln('');
        $output->writeln(sprintf(
            'Next: <comment>vortos:make:aggregate %s --context=%s</comment>',
            $name,
            $name,
        ));

        return Command::SUCCESS;
    }
}
