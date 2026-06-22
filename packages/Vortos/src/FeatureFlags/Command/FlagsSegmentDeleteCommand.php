<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;

#[AsCommand(name: 'vortos:flags:segment:delete', description: 'Delete a reusable audience segment')]
final class FlagsSegmentDeleteCommand extends Command
{
    public function __construct(private readonly SegmentStorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'Segment name');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');

        if ($this->storage->findByName($name) === null) {
            $output->writeln(sprintf('<error>Segment "%s" not found.</error>', $name));
            return Command::FAILURE;
        }

        $this->storage->delete($name);
        $output->writeln(sprintf('  <info>deleted:</info> %s', $name));

        return Command::SUCCESS;
    }
}
