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
    name: 'vortos:make:middleware',
    description: 'Generate a Kafka consumer middleware',
)]
final class MakeMiddlewareCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Middleware name without "Middleware" suffix (e.g. Correlation)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. User)')
            ->addOption('priority', null, InputOption::VALUE_OPTIONAL, 'Execution priority — higher runs first (default: 100)', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name     = (string) $input->getArgument('name');
        $context  = (string) $input->getOption('context');
        $priority = (string) $input->getOption('priority');

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=User</error>');
            return Command::FAILURE;
        }

        $vars = [
            'Namespace' => "App\\{$context}",
            'ClassName' => $name,
            'Priority'  => $priority,
        ];

        $output->writeln("<info>vortos:make:middleware</info> {$name} --context={$context}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Infrastructure/Messaging/{$name}Middleware.php",
            $this->engine->render('middleware', $vars),
            $output,
        );

        return Command::SUCCESS;
    }
}
