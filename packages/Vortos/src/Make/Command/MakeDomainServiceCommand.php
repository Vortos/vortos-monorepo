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
    name: 'vortos:make:domain-service',
    description: 'Generate a domain service marked with #[AsDomainService]',
)]
final class MakeDomainServiceCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Domain service class name (e.g. AgeGroupCalculator)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Bounded context folder (e.g. Registration)')
            ->addOption('aggregate', 'a', InputOption::VALUE_REQUIRED, 'Aggregate this service belongs to (e.g. Form)')
            ->addOption('shared', null, InputOption::VALUE_NONE, 'Generate into Domain/Shared/Service/ — used by more than one aggregate');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name      = (string) $input->getArgument('name');
        $aggregate = (string) $input->getOption('aggregate');
        $shared    = (bool) $input->getOption('shared');
        $context   = (string) ($input->getOption('context') ?: $aggregate);

        if ($aggregate === '' && !$shared) {
            $output->writeln('<error>Provide --aggregate or --shared.</error>');
            $output->writeln('');
            $output->writeln('  <info>--aggregate=<name></info>   The service is specific to one aggregate.');
            $output->writeln('                       Generates into <comment>Domain/{Aggregate}/Service/</comment>');
            $output->writeln('');
            $output->writeln('  <info>--shared</info>             The service is used by more than one aggregate in this context.');
            $output->writeln('                       Generates into <comment>Domain/Shared/Service/</comment>');
            return Command::FAILURE;
        }

        if ($aggregate !== '' && $shared) {
            $output->writeln('<error>--aggregate and --shared are mutually exclusive. Provide only one.</error>');
            return Command::FAILURE;
        }

        if ($shared) {
            $namespace = "App\\{$context}\\Domain\\Shared\\Service";
            $path      = "{$context}/Domain/Shared/Service/{$name}.php";
            $label     = '--shared';
        } else {
            $namespace = "App\\{$context}\\Domain\\{$aggregate}\\Service";
            $path      = "{$context}/Domain/{$aggregate}/Service/{$name}.php";
            $label     = "--aggregate={$aggregate}";
        }

        $vars = [
            'Namespace' => $namespace,
            'ClassName' => $name,
        ];

        $output->writeln("<info>vortos:make:domain-service</info> {$name} --context={$context} {$label}");
        $output->writeln('');

        $this->engine->write($path, $this->engine->render('domain-service', $vars), $output);

        $output->writeln('');
        $output->writeln('Domain services are stateless pure logic — no DB, HTTP, cache, or bus dependencies.');

        return Command::SUCCESS;
    }
}
