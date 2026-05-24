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
    name: 'vortos:make:domain-event',
    description: 'Generate a domain event',
)]
final class MakeDomainEventCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Event name without "Event" suffix (e.g. UserRegistered → UserRegisteredEvent)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Bounded context folder (e.g. User)')
            ->addOption('aggregate', 'a', InputOption::VALUE_REQUIRED, 'Aggregate root that emits this event (e.g. User)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name      = (string) $input->getArgument('name');
        $aggregate = (string) $input->getOption('aggregate');
        $context   = (string) ($input->getOption('context') ?: $aggregate);

        if ($aggregate === '') {
            $output->writeln('<error>--aggregate is required.</error>');
            $output->writeln('');
            $output->writeln('  Provide the aggregate root that emits this event:');
            $output->writeln('  <info>--aggregate=TrainingSession</info>   Event lives inside the TrainingSession aggregate boundary.');
            return Command::FAILURE;
        }

        $className = $name . 'Event';

        $vars = [
            'Namespace' => "App\\{$context}\\Domain\\{$aggregate}\\Event",
            'ClassName' => $className,
        ];

        $output->writeln("<info>vortos:make:domain-event</info> {$name} --context={$context} --aggregate={$aggregate}");
        $output->writeln('');

        $this->engine->write(
            "{$context}/Domain/{$aggregate}/Event/{$className}.php",
            $this->engine->render('domain-event', $vars),
            $output,
        );

        $output->writeln('');
        $output->writeln(sprintf('Next: record in your aggregate — <info>$this->recordEvent(new %s(...))</info>', $className));

        return Command::SUCCESS;
    }
}
