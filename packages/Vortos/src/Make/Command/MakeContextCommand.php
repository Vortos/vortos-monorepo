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
        'Domain/Entity',
        'Domain/Event',
        'Domain/Exception',
        'Domain/Repository',
        'Domain/ValueObject',
        'Application/Command',
        'Application/Query',
        'Application/EventHandler',
        'Application/Projection',
        'Infrastructure/Repository',
        'Infrastructure/Policy',
        'Infrastructure/Messaging',
        'Representation/Controller',
        'Representation/Request',
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
            'Next: <comment>vortos:make:entity %s --context=%s</comment>',
            $name,
            $name,
        ));

        return Command::SUCCESS;
    }
}
