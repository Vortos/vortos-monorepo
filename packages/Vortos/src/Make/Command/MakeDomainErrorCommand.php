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
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Bounded context folder (e.g. User)')
            ->addOption('aggregate', 'a', InputOption::VALUE_REQUIRED, 'Aggregate root this error belongs to (e.g. User)')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'HTTP status code', '422');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name      = (string) $input->getArgument('name');
        $aggregate = (string) $input->getOption('aggregate');
        $context   = (string) ($input->getOption('context') ?: $aggregate);
        $status    = (string) $input->getOption('status');

        if ($aggregate === '') {
            $output->writeln('<error>--aggregate is required.</error>');
            $output->writeln('');
            $output->writeln('  Provide the aggregate root this error belongs to:');
            $output->writeln('  <info>--aggregate=TrainingSession</info>   Error lives inside the TrainingSession aggregate boundary.');
            return Command::FAILURE;
        }

        $vars = [
            'Namespace'  => "App\\{$context}\\Domain\\{$aggregate}\\Error",
            'ClassName'  => $name,
            'HttpStatus' => $status,
            'ErrorCode'  => $this->toErrorCode($name),
        ];

        $output->writeln("<info>vortos:make:domain-error</info> {$name} --context={$context} --aggregate={$aggregate} --status={$status}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Domain/{$aggregate}/Error/{$name}Error.php",
            $this->engine->render('domain-error', $vars),
            $output,
        );

        $output->writeln('');
        $output->writeln(sprintf('Next: throw from domain methods — <info>throw %sError::because(\'...\')</info>', $name));

        return Command::SUCCESS;
    }

    private function toErrorCode(string $name): string
    {
        return strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $name));
    }
}
