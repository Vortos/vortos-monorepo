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
    name: 'vortos:make:domain-exception',
    description: 'Generate a domain exception',
)]
final class MakeDomainExceptionCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Exception name without "Exception" suffix (e.g. UserNotFound)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. User)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name    = (string) $input->getArgument('name');
        $context = (string) $input->getOption('context');

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=User</error>');
            return Command::FAILURE;
        }

        $vars = [
            'Namespace' => "App\\{$context}",
            'ClassName' => $name,
        ];

        $output->writeln("<info>vortos:make:domain-exception</info> {$name} --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Domain/Exception/{$name}Exception.php",
            $this->engine->render('domain-exception', $vars),
            $output,
        );

        return Command::SUCCESS;
    }
}
