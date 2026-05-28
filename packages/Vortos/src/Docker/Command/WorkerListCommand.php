<?php

declare(strict_types=1);

namespace Vortos\Docker\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Docker\Worker\WorkerProcessRegistry;

#[AsCommand(name: 'vortos:worker:list', description: 'List worker processes contributed by installed Vortos packages')]
final class WorkerListCommand extends Command
{
    public function __construct(private readonly WorkerProcessRegistry $registry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];

        foreach ($this->registry->all() as $definition) {
            $rows[] = [$definition->name, $definition->command, $definition->description];
        }

        if ($rows === []) {
            $io->info('No Vortos worker definitions are registered.');
            return Command::SUCCESS;
        }

        $io->table(['Worker', 'Command', 'Description'], $rows);

        return Command::SUCCESS;
    }
}
