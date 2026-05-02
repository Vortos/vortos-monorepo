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
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

#[AsCommand(name: 'vortos:flags:create', description: 'Create a new feature flag')]
final class FlagsCreateCommand extends Command
{
    public function __construct(private readonly FlagStorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Flag name (e.g. new-checkout)')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Short description', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
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

        $this->storage->save($flag);

        $output->writeln(sprintf('  <info>created:</info> %s <fg=gray>(disabled — run vortos:flags:enable %s to activate)</>', $name, $name));

        return Command::SUCCESS;
    }
}
