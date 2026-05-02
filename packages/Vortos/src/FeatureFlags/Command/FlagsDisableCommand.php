<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

#[AsCommand(name: 'vortos:flags:disable', description: 'Disable a feature flag (kill switch — off for everyone instantly)')]
final class FlagsDisableCommand extends Command
{
    public function __construct(private readonly FlagStorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Flag name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $flag = $this->storage->findByName($name);

        if ($flag === null) {
            $output->writeln(sprintf('<error>Flag "%s" not found.</error>', $name));
            return Command::FAILURE;
        }

        $this->storage->save($flag->withEnabled(false));
        $output->writeln(sprintf('  <fg=red>disabled:</> %s <fg=gray>(off for all users immediately)</>', $name));

        return Command::SUCCESS;
    }
}
