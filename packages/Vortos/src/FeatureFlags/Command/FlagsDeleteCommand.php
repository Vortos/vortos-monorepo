<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

#[AsCommand(name: 'vortos:flags:delete', description: 'Delete a feature flag permanently')]
final class FlagsDeleteCommand extends Command
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly FlagWriteService $writeService,
        private readonly FlagScopeContext $scope = new FlagScopeContext(),
        private readonly ProjectContext $projectContext = new ProjectContext(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Flag name')
            ->addOption('force',   null, InputOption::VALUE_NONE, 'Skip confirmation')
            ->addOption('env',     null, InputOption::VALUE_REQUIRED, 'Target environment (default: production)', FlagScopeContext::ENV_PRODUCTION)
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project slug (default: default)', ProjectContext::DEFAULT_PROJECT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env     = (string) ($input->getOption('env') ?? FlagScopeContext::ENV_PRODUCTION);
        $project = (string) ($input->getOption('project') ?? ProjectContext::DEFAULT_PROJECT);
        $this->scope->withEnvironment($env);
        $this->projectContext->withProject($project);

        $name = (string) $input->getArgument('name');

        if ($this->storage->findByName($name) === null) {
            $output->writeln(sprintf('<error>Flag "%s" not found.</error>', $name));
            return Command::FAILURE;
        }

        if (!$input->getOption('force')) {
            $output->writeln(sprintf('<comment>Delete flag "%s"? This cannot be undone. Add --force to confirm.</comment>', $name));
            return Command::SUCCESS;
        }

        $this->writeService->archiveAndDelete($name, 'cli');
        $output->writeln(sprintf('  <fg=red>deleted:</> %s', $name));

        return Command::SUCCESS;
    }
}
