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
    name: 'vortos:make:value-object',
    description: 'Generate a value object',
)]
final class MakeValueObjectCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Value object class name (e.g. Email)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Bounded context folder (e.g. User)')
            ->addOption('aggregate', 'a', InputOption::VALUE_REQUIRED, 'Aggregate this value object belongs to (e.g. User)')
            ->addOption('shared', null, InputOption::VALUE_NONE, 'Generate into Domain/Shared/ValueObjects/ — used by more than one aggregate')
            ->addOption('embeddable', null, InputOption::VALUE_NONE, 'Generate as a Doctrine embeddable (requires vortos/persistence-orm)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name       = (string) $input->getArgument('name');
        $aggregate  = (string) $input->getOption('aggregate');
        $shared     = (bool) $input->getOption('shared');
        $embeddable = (bool) $input->getOption('embeddable');
        $context    = (string) ($input->getOption('context') ?: $aggregate);

        if ($aggregate === '' && !$shared) {
            $output->writeln('<error>Provide --aggregate or --shared.</error>');
            $output->writeln('');
            $output->writeln('  <info>--aggregate=<name></info>   The value object is used only within one aggregate.');
            $output->writeln('                       Example: Duration used only inside TrainingSession.');
            $output->writeln('                       Generates into <comment>Domain/{Aggregate}/ValueObjects/</comment>');
            $output->writeln('');
            $output->writeln('  <info>--shared</info>             The value object is used by more than one aggregate in this context.');
            $output->writeln('                       Example: Email used by both Athlete and Buyer.');
            $output->writeln('                       Generates into <comment>Domain/Shared/ValueObjects/</comment>');
            return Command::FAILURE;
        }

        if ($aggregate !== '' && $shared) {
            $output->writeln('<error>--aggregate and --shared are mutually exclusive. Provide only one.</error>');
            return Command::FAILURE;
        }

        if ($embeddable && !class_exists(\Vortos\PersistenceOrm\Aggregate\AggregateRoot::class)) {
            $output->writeln('<error>--embeddable requires vortos/persistence-orm. Install it or omit the flag.</error>');
            return Command::FAILURE;
        }

        $stubName = $embeddable ? 'value-object-embeddable' : 'value-object';

        if ($shared) {
            $namespace = "App\\{$context}\\Domain\\Shared\\ValueObjects";
            $path      = "{$context}/Domain/Shared/ValueObjects/{$name}.php";
            $label     = '--shared';
        } else {
            $namespace = "App\\{$context}\\Domain\\{$aggregate}\\ValueObjects";
            $path      = "{$context}/Domain/{$aggregate}/ValueObjects/{$name}.php";
            $label     = "--aggregate={$aggregate}";
        }

        $vars = [
            'Namespace' => $namespace,
            'ClassName' => $name,
        ];

        $output->writeln("<info>vortos:make:value-object</info> {$name} --context={$context} {$label}" . ($embeddable ? ' <fg=cyan>[embeddable]</>' : ''));
        $output->writeln('');

        $this->engine->write($path, $this->engine->render($stubName, $vars), $output);

        if ($embeddable) {
            $output->writeln('');
            $output->writeln('Add <info>#[ORM\Embedded(class: ' . $name . '::class)]</info> on the aggregate root property.');
        }

        return Command::SUCCESS;
    }
}
