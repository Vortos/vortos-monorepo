<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Layer\LayerStorageInterface;
use Vortos\FeatureFlags\Layer\Validation\LayerValidator;

#[AsCommand(name: 'vortos:flags:layer:add-member', description: 'Add a flag experiment as a member of a layer')]
final class FlagsLayerAddMemberCommand extends Command
{
    public function __construct(
        private readonly LayerStorageInterface $layers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('layer-name', InputArgument::REQUIRED, 'Layer name')
            ->addArgument('flag-name', InputArgument::REQUIRED, 'Flag name to assign as an experiment')
            ->addArgument('weight', InputArgument::REQUIRED, 'Slice weight in 0–10000 bucket units');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $layerName = (string) $input->getArgument('layer-name');
        $flagName  = (string) $input->getArgument('flag-name');
        $weight    = (int) $input->getArgument('weight');

        $layer = $this->layers->findByName($layerName);

        if ($layer === null) {
            $output->writeln(sprintf('<error>Layer "%s" not found.</error>', $layerName));
            return Command::FAILURE;
        }

        // Check flag not already in another layer
        $existing = $this->layers->findByFlagName($flagName);
        if ($existing !== null && $existing->id !== $layer->id) {
            $output->writeln(sprintf(
                '<error>Flag "%s" is already assigned to layer "%s".</error>',
                $flagName,
                $existing->name,
            ));
            return Command::FAILURE;
        }

        // Rebuild member weight map preserving existing members
        $memberWeights = [];
        foreach ($layer->members as $m) {
            $memberWeights[$m->flagName] = $m->weight;
        }
        $memberWeights[$flagName] = $weight;

        try {
            $updated = LayerValidator::buildLayer(
                $layer->id,
                $layer->name,
                $layer->salt,
                $layer->holdoutWeight,
                $memberWeights,
                $layer->projectId,
            );
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $this->layers->save($updated);

        $output->writeln(sprintf(
            '  <info>added member:</info> %s → layer %s <fg=gray>(weight: %d/10000 | total: %d/10000)</>',
            $flagName,
            $layerName,
            $weight,
            $updated->totalAllocated(),
        ));

        return Command::SUCCESS;
    }
}
