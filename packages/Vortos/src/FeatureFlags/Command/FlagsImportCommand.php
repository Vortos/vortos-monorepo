<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\GitOps\FlagDefinitionImporter;

#[AsCommand(name: 'vortos:flags:import', description: 'Import flag definitions from a declarative JSON file')]
final class FlagsImportCommand extends Command
{
    public function __construct(
        private readonly FlagDefinitionImporter $importer,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Path to the definition JSON file')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change without applying')
            ->addOption('actor', null, InputOption::VALUE_REQUIRED, 'Actor ID for audit trail', 'gitops');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $filePath = (string) $input->getArgument('file');

        if (!file_exists($filePath)) {
            $output->writeln(sprintf('<error>File not found: %s</error>', $filePath));
            return Command::FAILURE;
        }

        $raw = file_get_contents($filePath);
        if ($raw === false) {
            $output->writeln(sprintf('<error>Cannot read file: %s</error>', $filePath));
            return Command::FAILURE;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            $output->writeln('<error>Invalid JSON in definition file</error>');
            return Command::FAILURE;
        }

        $dryRun  = (bool) $input->getOption('dry-run');
        $actorId = (string) $input->getOption('actor');

        try {
            $result = $this->importer->import($data, $dryRun, $actorId);
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        if (!$result->hasChanges()) {
            $output->writeln('<info>No changes needed — runtime state matches definition file.</info>');
            return Command::SUCCESS;
        }

        $prefix = $dryRun ? '<comment>[dry-run]</comment> ' : '';

        foreach ($result->created as $name) {
            $output->writeln(sprintf('  %s<fg=green>+ %s</>', $prefix, $name));
        }
        foreach ($result->updated as $name) {
            $output->writeln(sprintf('  %s<fg=yellow>~ %s</>', $prefix, $name));
        }
        foreach ($result->deleted as $name) {
            $output->writeln(sprintf('  %s<fg=red>- %s</>', $prefix, $name));
        }

        $output->writeln('');
        $output->writeln(sprintf('<info>%s</info>', $result->summary()));

        return Command::SUCCESS;
    }
}
