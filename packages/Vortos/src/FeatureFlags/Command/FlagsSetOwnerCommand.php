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

#[AsCommand(name: 'vortos:flags:set-owner', description: 'Set or clear the owner (team/squad) of a feature flag')]
final class FlagsSetOwnerCommand extends Command
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
            ->addOption('owner',   null, InputOption::VALUE_REQUIRED, 'Owner identifier (team slug, email). Omit to clear.')
            ->addOption('reason',  null, InputOption::VALUE_REQUIRED, 'Reason for the change')
            ->addOption('env',     null, InputOption::VALUE_REQUIRED, 'Environment', FlagScopeContext::ENV_PRODUCTION)
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project slug', ProjectContext::DEFAULT_PROJECT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->scope->withEnvironment((string) ($input->getOption('env') ?? FlagScopeContext::ENV_PRODUCTION));
        $this->projectContext->withProject((string) ($input->getOption('project') ?? ProjectContext::DEFAULT_PROJECT));

        $name  = (string) $input->getArgument('name');
        $owner = $input->getOption('owner') !== null ? (string) $input->getOption('owner') : null;

        if ($this->storage->findByName($name) === null) {
            $output->writeln(sprintf('<error>Flag "%s" not found.</error>', $name));
            return Command::FAILURE;
        }

        $this->writeService->setOwner($name, $owner, 'cli', $input->getOption('reason'));

        if ($owner !== null) {
            $output->writeln(sprintf('  <info>owner set:</info> %s → %s', $name, $owner));
        } else {
            $output->writeln(sprintf('  <fg=yellow>owner cleared:</> %s', $name));
        }

        return Command::SUCCESS;
    }
}
