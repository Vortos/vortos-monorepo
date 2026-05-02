<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

#[AsCommand(name: 'vortos:flags:delete', description: 'Delete a feature flag permanently')]
final class FlagsDeleteCommand extends Command
{
    public function __construct(private readonly FlagStorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Flag name')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');

        if ($this->storage->findByName($name) === null) {
            $output->writeln(sprintf('<error>Flag "%s" not found.</error>', $name));
            return Command::FAILURE;
        }

        if (!$input->getOption('force')) {
            $output->writeln(sprintf('<comment>Delete flag "%s"? This cannot be undone. Add --force to confirm.</comment>', $name));
            return Command::SUCCESS;
        }

        $this->storage->delete($name);
        $output->writeln(sprintf('  <fg=red>deleted:</> %s', $name));

        return Command::SUCCESS;
    }
}
