<?php

declare(strict_types=1);

namespace Vortos\Observability\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Observability\Service\ObservabilityTemplateRegistry;

#[AsCommand(
    name: 'vortos:observability:list',
    description: 'List available Vortos observability template stacks',
)]
final class ListObservabilityStacksCommand extends Command
{
    public function __construct(private readonly ObservabilityTemplateRegistry $registry)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];

        foreach ($this->registry->stacks() as $stack) {
            $rows[] = [
                $stack->name,
                $stack->description,
                implode("\n", $stack->files),
            ];
        }

        $io->table(['Stack', 'Description', 'Files'], $rows);

        return Command::SUCCESS;
    }
}

