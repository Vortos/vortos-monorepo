<?php

declare(strict_types=1);

namespace Vortos\Foundation\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'vortos:debug:bindings',
    description: 'List all interface → class bindings registered via #[DefaultImpl]',
)]
final class DebugBindingsCommand extends Command
{
    /** @param array<string, array{class: string, file: string}> $bindings */
    public function __construct(
        private readonly array $bindings,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $io->title('Default Implementation Bindings');

        if (empty($this->bindings)) {
            $io->warning('No #[DefaultImpl] bindings registered.');
            return Command::SUCCESS;
        }

        $showPath = $output->isVerbose();

        $rows = [];
        foreach ($this->bindings as $interface => $info) {
            $row = [
                sprintf('<fg=cyan>%s</>', $interface),
                sprintf('<fg=green>%s</>', $info['class']),
            ];

            if ($showPath) {
                $row[] = sprintf('<fg=gray>%s</>', $info['file']);
            }

            $rows[] = $row;
        }

        $headers = ['Interface', 'Implementation'];
        if ($showPath) {
            $headers[] = 'File';
        }

        $io->table($headers, $rows);

        $io->text(sprintf('<fg=yellow>%d binding(s) registered.</>', count($this->bindings)));

        return Command::SUCCESS;
    }
}
