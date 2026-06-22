<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\Storage\ProjectStorageInterface;

#[AsCommand(name: 'vortos:flags:project:delete', description: 'Delete a feature flag project workspace')]
final class FlagsProjectDeleteCommand extends Command
{
    public function __construct(
        private readonly ProjectStorageInterface $projects,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Project slug to delete')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Skip confirmation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $slug = (string) $input->getArgument('slug');

        if ($slug === ProjectContext::DEFAULT_PROJECT) {
            $output->writeln('<error>The "default" project cannot be deleted.</error>');
            return Command::FAILURE;
        }

        if ($this->projects->findBySlug($slug) === null) {
            $output->writeln(sprintf('<error>Project "%s" not found.</error>', $slug));
            return Command::FAILURE;
        }

        if (!$input->getOption('force')) {
            $output->writeln(sprintf(
                '<comment>Delete project "%s"? Flags in this project will be orphaned. Add --force to confirm.</comment>',
                $slug,
            ));
            return Command::SUCCESS;
        }

        $this->projects->delete($slug);
        $output->writeln(sprintf('  <fg=red>deleted project:</> %s', $slug));

        return Command::SUCCESS;
    }
}
