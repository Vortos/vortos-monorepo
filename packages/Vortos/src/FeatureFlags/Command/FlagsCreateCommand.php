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
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

#[AsCommand(name: 'vortos:flags:create', description: 'Create a new feature flag')]
final class FlagsCreateCommand extends Command
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
            ->addArgument('name', InputArgument::REQUIRED, 'Flag name (e.g. new-checkout)')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Short description', '')
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

        if ($this->storage->findByName($name) !== null) {
            $output->writeln(sprintf('<error>Flag "%s" already exists.</error>', $name));
            return Command::FAILURE;
        }

        $now  = new \DateTimeImmutable();
        $flag = new FeatureFlag(
            id:          (string) Uuid::v4(),
            name:        $name,
            description: (string) $input->getOption('description'),
            enabled:     false,
            rules:       [],
            variants:    null,
            createdAt:   $now,
            updatedAt:   $now,
        );

        $this->writeService->create($flag, 'cli');

        $output->writeln(sprintf('  <info>created:</info> %s <fg=gray>(disabled — run vortos:flags:enable %s to activate)</>', $name, $name));

        return Command::SUCCESS;
    }
}
