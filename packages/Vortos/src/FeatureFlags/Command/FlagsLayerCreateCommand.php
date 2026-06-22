<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Uid\Uuid;
use Vortos\FeatureFlags\Layer\LayerStorageInterface;
use Vortos\FeatureFlags\Layer\Validation\LayerValidator;

#[AsCommand(name: 'vortos:flags:layer:create', description: 'Create a mutual-exclusion experiment layer')]
final class FlagsLayerCreateCommand extends Command
{
    public function __construct(
        private readonly LayerStorageInterface $layers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Layer name (unique within a project)')
            ->addOption('salt', 's', InputOption::VALUE_REQUIRED, 'Bucketing salt (defaults to layer name if omitted)')
            ->addOption('holdout', null, InputOption::VALUE_REQUIRED, 'Holdout weight in 0–10000 bucket units (0 = no holdout)', '0')
            ->addOption('project', 'p', InputOption::VALUE_REQUIRED, 'Project ID', 'default')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name    = (string) $input->getArgument('name');
        $salt    = (string) ($input->getOption('salt') ?? $name);
        $project = (string) ($input->getOption('project') ?? 'default');
        $holdout = (int) $input->getOption('holdout');

        if ($this->layers->findByName($name) !== null) {
            $output->writeln(sprintf('<error>Layer "%s" already exists.</error>', $name));
            return Command::FAILURE;
        }

        try {
            $layer = LayerValidator::buildLayer((string) Uuid::v4(), $name, $salt, $holdout, [], $project);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $this->layers->save($layer);

        if ($input->getOption('json')) {
            $output->writeln(json_encode([
                'id'            => $layer->id,
                'name'          => $layer->name,
                'salt'          => $layer->salt,
                'holdoutWeight' => $layer->holdoutWeight,
                'projectId'     => $layer->projectId,
            ], JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $output->writeln(sprintf(
            '  <info>created layer:</info> %s <fg=gray>(id: %s | holdout: %d/10000)</>',
            $layer->name,
            $layer->id,
            $layer->holdoutWeight,
        ));

        return Command::SUCCESS;
    }
}
