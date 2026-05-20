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
    name: 'vortos:make:domain-error',
    description: 'Generate a domain error',
)]
final class MakeDomainErrorCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Error name without "Error" suffix (e.g. UserNotFound)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Domain context folder (e.g. User)')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'HTTP status code', '422');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name    = (string) $input->getArgument('name');
        $context = (string) $input->getOption('context');
        $status  = (string) $input->getOption('status');

        if ($context === '') {
            $output->writeln('<error>--context is required. Example: --context=User</error>');
            return Command::FAILURE;
        }

        $vars = [
            'Namespace'  => "App\\{$context}",
            'ClassName'  => $name,
            'HttpStatus' => $status,
            'ErrorCode'  => $this->toErrorCode($name),
        ];

        $output->writeln("<info>vortos:make:domain-error</info> {$name} --context={$context} --status={$status}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Domain/Error/{$name}Error.php",
            $this->engine->render('domain-error', $vars),
            $output,
        );

        return Command::SUCCESS;
    }

    private function toErrorCode(string $name): string
    {
        return strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
