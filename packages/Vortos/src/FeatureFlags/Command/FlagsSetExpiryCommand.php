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

#[AsCommand(name: 'vortos:flags:set-expiry', description: 'Set or clear the expiry date of a feature flag')]
final class FlagsSetExpiryCommand extends Command
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
            ->addOption('date',    null, InputOption::VALUE_REQUIRED, 'Expiry date (ISO 8601, e.g. 2026-12-31). Omit to clear.')
            ->addOption('reason',  null, InputOption::VALUE_REQUIRED, 'Reason for the change')
            ->addOption('env',     null, InputOption::VALUE_REQUIRED, 'Environment', FlagScopeContext::ENV_PRODUCTION)
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project slug', ProjectContext::DEFAULT_PROJECT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->scope->withEnvironment((string) ($input->getOption('env') ?? FlagScopeContext::ENV_PRODUCTION));
        $this->projectContext->withProject((string) ($input->getOption('project') ?? ProjectContext::DEFAULT_PROJECT));

        $name    = (string) $input->getArgument('name');
        $dateStr = $input->getOption('date');

        if ($this->storage->findByName($name) === null) {
            $output->writeln(sprintf('<error>Flag "%s" not found.</error>', $name));
            return Command::FAILURE;
        }

        $expiresAt = null;
        if ($dateStr !== null) {
            try {
                $expiresAt = new \DateTimeImmutable((string) $dateStr);
            } catch (\Exception) {
                $output->writeln(sprintf('<error>Invalid date "%s". Use ISO 8601 format, e.g. 2026-12-31.</error>', $dateStr));
                return Command::FAILURE;
            }
        }

        $this->writeService->setExpiry($name, $expiresAt, 'cli', $input->getOption('reason'));

        if ($expiresAt !== null) {
            $output->writeln(sprintf(
                '  <info>expiry set:</info> %s → <fg=yellow>%s</>',
                $name,
                $expiresAt->format('Y-m-d'),
            ));
        } else {
            $output->writeln(sprintf('  <fg=yellow>expiry cleared:</> %s', $name));
        }

        return Command::SUCCESS;
    }
}
