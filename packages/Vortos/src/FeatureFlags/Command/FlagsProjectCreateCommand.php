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
use Vortos\FeatureFlags\Project;
use Vortos\FeatureFlags\Storage\ProjectStorageInterface;

#[AsCommand(name: 'vortos:flags:project:create', description: 'Create a new feature flag project workspace')]
final class FlagsProjectCreateCommand extends Command
{
    public function __construct(
        private readonly ProjectStorageInterface $projects,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Human-readable project name (e.g. "Mobile App")')
            ->addOption('description', 'd', InputOption::VALUE_REQUIRED, 'Short description', '');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name        = (string) $input->getArgument('name');
        $description = (string) $input->getOption('description');
        $slug        = Project::slugify($name);

        if ($this->projects->findBySlug($slug) !== null) {
            $output->writeln(sprintf('<error>Project with slug "%s" already exists.</error>', $slug));
            return Command::FAILURE;
        }

        $now     = new \DateTimeImmutable();
        $project = new Project(
            id:          (string) Uuid::v4(),
            name:        $name,
            slug:        $slug,
            description: $description,
            createdAt:   $now,
            updatedAt:   $now,
        );

        $this->projects->save($project);

        $output->writeln(sprintf('  <info>created project:</info> %s <fg=gray>(slug: %s)</>', $name, $slug));

        return Command::SUCCESS;
    }
}
