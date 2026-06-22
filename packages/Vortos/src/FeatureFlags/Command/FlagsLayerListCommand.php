<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Layer\Layer;
use Vortos\FeatureFlags\Layer\LayerStorageInterface;

#[AsCommand(name: 'vortos:flags:layer:list', description: 'List all mutual-exclusion experiment layers')]
final class FlagsLayerListCommand extends Command
{
    public function __construct(
        private readonly LayerStorageInterface $layers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Filter by project ID')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $layers    = $this->layers->findAll();
        $projectId = $input->getOption('project');

        if ($projectId !== null) {
            $layers = array_filter($layers, fn(Layer $l) => $l->projectId === $projectId);
            $layers = array_values($layers);
        }

        if ($input->getOption('json')) {
            $output->writeln(json_encode(array_map(fn(Layer $l) => [
                'id'            => $l->id,
                'name'          => $l->name,
                'salt'          => $l->salt,
                'holdoutWeight' => $l->holdoutWeight,
                'projectId'     => $l->projectId,
                'members'       => array_map(fn($m) => [
                    'flagName'   => $m->flagName,
                    'sliceStart' => $m->sliceStart,
                    'weight'     => $m->weight,
                ], $l->members),
                'totalAllocated' => $l->totalAllocated(),
            ], $layers), JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln(sprintf(' <fg=white;options=bold>Layers</> <fg=gray>(%d)</>', count($layers)));
        $output->writeln('');

        if (empty($layers)) {
            $output->writeln(' <fg=yellow>No layers defined. Run vortos:flags:layer:create to add one.</>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $style = (new TableStyle())
            ->setHorizontalBorderChars('')
            ->setVerticalBorderChars(' ')
            ->setCrossingChars('', '', '', '', '', '', '', '', '');

        $table = new Table($output);
        $table->setStyle($style);
        $table->setHeaders([
            '<fg=gray>Name</>',
            '<fg=gray>Project</>',
            '<fg=gray>Members</>',
            '<fg=gray>Holdout</>',
            '<fg=gray>Allocated</>',
        ]);

        foreach ($layers as $layer) {
            $table->addRow([
                sprintf('<fg=cyan>%s</>', $layer->name),
                sprintf('<fg=gray>%s</>', $layer->projectId),
                sprintf('<fg=white>%d</>', count($layer->members)),
                sprintf('<fg=gray>%d/10000</>', $layer->holdoutWeight),
                sprintf(
                    $layer->totalAllocated() >= 10000 ? '<fg=yellow>%d/10000</>' : '<fg=gray>%d/10000</>',
                    $layer->totalAllocated(),
                ),
            ]);
        }

        $table->render();
        $output->writeln('');

        return Command::SUCCESS;
    }
}
