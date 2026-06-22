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
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Segment;
use Vortos\FeatureFlags\Storage\SegmentStorageInterface;

#[AsCommand(name: 'vortos:flags:segment:create', description: 'Create or update a reusable audience segment')]
final class FlagsSegmentCreateCommand extends Command
{
    public function __construct(private readonly SegmentStorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Segment name (e.g. beta-testers)')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Short description', '')
            ->addOption('rules', null, InputOption::VALUE_REQUIRED, 'Rules as a JSON array (each item a FlagRule, supports groups)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name  = (string) $input->getArgument('name');
        $rules = [];

        $rulesJson = $input->getOption('rules');
        if ($rulesJson !== null) {
            try {
                $decoded = json_decode((string) $rulesJson, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                $output->writeln(sprintf('<error>Invalid --rules JSON: %s</error>', $e->getMessage()));
                return Command::FAILURE;
            }

            if (!is_array($decoded) || array_is_list($decoded) === false) {
                $output->writeln('<error>--rules must be a JSON array of rule objects.</error>');
                return Command::FAILURE;
            }

            try {
                $rules = array_map(fn(array $r) => FlagRule::fromArray($r), $decoded);
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Could not parse rules: %s</error>', $e->getMessage()));
                return Command::FAILURE;
            }
        }

        $existing = $this->storage->findByName($name);
        $now      = new \DateTimeImmutable();

        $segment = new Segment(
            id:          $existing?->id ?? (string) Uuid::v4(),
            name:        $name,
            description: (string) $input->getOption('description'),
            rules:       $rules,
            createdAt:   $existing?->createdAt ?? $now,
            updatedAt:   $now,
        );

        $this->storage->save($segment);

        $output->writeln(sprintf('  <info>%s:</info> %s <fg=gray>(%d rule(s))</>', $existing ? 'updated' : 'created', $name, count($rules)));

        return Command::SUCCESS;
    }
}
