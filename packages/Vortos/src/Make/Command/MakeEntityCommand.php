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
    name: 'vortos:make:entity',
    description: 'Generate a plain domain entity and its typed EntityId (for non-root entities within an aggregate)',
)]
final class MakeEntityCommand extends Command
{
    public function __construct(private readonly GeneratorEngine $engine)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Entity class name (e.g. OrderLine)')
            ->addOption('context', 'c', InputOption::VALUE_REQUIRED, 'Bounded context folder (e.g. Order)')
            ->addOption('aggregate', 'a', InputOption::VALUE_REQUIRED, 'Aggregate root this entity belongs to (e.g. Order)')
            ->addOption('no-orm', null, InputOption::VALUE_NONE, 'Generate without ORM annotations even if vortos/persistence-orm is installed');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name      = (string) $input->getArgument('name');
        $aggregate = (string) $input->getOption('aggregate');
        $context   = (string) ($input->getOption('context') ?: $aggregate);

        if ($aggregate === '') {
            $output->writeln('<error>--aggregate is required.</error>');
            $output->writeln('');
            $output->writeln('  Provide the aggregate root this entity belongs to:');
            $output->writeln('  <info>--aggregate=Order</info>   Entity lives inside the Order aggregate boundary.');
            $output->writeln('');
            $output->writeln('  Child entities are always owned by exactly one aggregate root.');
            $output->writeln('  They are persisted through the root\'s repository — never directly.');
            return Command::FAILURE;
        }

        $ormDetected = class_exists(\Vortos\PersistenceOrm\Aggregate\AggregateRoot::class);
        $noOrm       = (bool) $input->getOption('no-orm');
        $ormActive   = $ormDetected && !$noOrm;
        $stubName    = $ormActive ? 'entity-child-orm' : 'entity';

        $namespace = "App\\{$context}\\Domain\\{$aggregate}\\Entities";

        $vars = [
            'Namespace' => $namespace,
            'ClassName' => $name,
        ];

        $output->writeln("<info>vortos:make:entity</info> {$name} --context={$context} --aggregate={$aggregate}" . ($ormActive ? ' <fg=cyan>[ORM]</>' : ''));
        $output->writeln('');

        if ($ormDetected && !$noOrm) {
            $output->writeln('<comment>ORM mode:</comment> generating child entity with Doctrine annotations.');
            $output->writeln('Add <info>#[ORM\OneToMany(..., cascade: [\'persist\', \'remove\'], orphanRemoval: true)]</info> on your aggregate root.');
            $output->writeln('To generate without ORM: add <info>--no-orm</info>');
            $output->writeln('');
        }

        $this->engine->write("{$context}/Domain/{$aggregate}/Entities/{$name}.php", $this->engine->render($stubName, $vars), $output);
        $this->engine->write("{$context}/Domain/{$aggregate}/Entities/{$name}Id.php", $this->engine->render('entity-id', $vars), $output);

        return Command::SUCCESS;
    }
}
