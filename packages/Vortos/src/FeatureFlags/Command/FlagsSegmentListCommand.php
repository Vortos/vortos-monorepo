<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Segment;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;

#[AsCommand(name: 'vortos:flags:segment:list', description: 'List reusable audience segments')]
final class FlagsSegmentListCommand extends Command
{
    public function __construct(private readonly SegmentStorageInterface $storage)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $segments = $this->storage->findAll();

        if ($segments === []) {
            $output->writeln('<comment>No segments defined.</comment>');
            return Command::SUCCESS;
        }

        foreach ($segments as $segment) {
            /** @var Segment $segment */
            $output->writeln(sprintf(
                '  <info>%s</info> <fg=gray>(%d rule(s))</> %s',
                $segment->name,
                count($segment->rules),
                $segment->description !== '' ? '— ' . $segment->description : '',
            ));
        }

        return Command::SUCCESS;
    }
}
