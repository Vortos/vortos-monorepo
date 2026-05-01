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
    name: 'vortos:make:policy',
    description: 'Generate an authorization policy',
)]
final class MakePolicyCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Policy name without "Policy" suffix (e.g. Athlete)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. Athlete)')
            ->addOption('resource', 'r', InputOption::VALUE_REQUIRED, 'Resource slug used in #[AsPolicy] (e.g. athletes)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name     = (string) $input->getArgument('name');
        $context  = (string) $input->getOption('context');
        $resource = (string) ($input->getOption('resource') ?: strtolower($name) . 's');

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=User</error>');
            return Command::FAILURE;
        }

        $vars = [
            'Namespace' => "App\\{$context}",
            'ClassName' => $name,
            'Resource'  => $resource,
        ];

        $output->writeln("<info>vortos:make:policy</info> {$name} --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Infrastructure/Policy/{$name}Policy.php",
            $this->engine->render('policy', $vars),
            $output,
        );

        return Command::SUCCESS;
    }
}
