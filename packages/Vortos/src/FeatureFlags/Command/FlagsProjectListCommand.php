<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Storage\ProjectStorageInterface;

#[AsCommand(name: 'vortos:flags:project:list', description: 'List all feature flag project workspaces')]
final class FlagsProjectListCommand extends Command
{
    public function __construct(
        private readonly ProjectStorageInterface $projects,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('json', null, InputOption::VALUE_NONE, 'Output as JSON');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $projects = $this->projects->findAll();

        if ($input->getOption('json')) {
            $output->writeln(json_encode(array_map(fn($p) => $p->toArray(), $projects), JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $output->writeln('');
        $output->writeln(sprintf(' <fg=white;options=bold>Projects</> <fg=gray>(%d)</>', count($projects)));
        $output->writeln('');

        if (empty($projects)) {
            $output->writeln(' <fg=yellow>No projects defined. Run vortos:flags:project:create to add one.</>');
            $output->writeln('');
            return Command::SUCCESS;
        }

        $style = (new TableStyle())
            ->setHorizontalBorderChars('')
            ->setVerticalBorderChars(' ')
            ->setCrossingChars('', '', '', '', '', '', '', '', '');

        $table = new Table($output);
        $table->setStyle($style);
        $table->setHeaders(['<fg=gray>Slug</>', '<fg=gray>Name</>', '<fg=gray>Description</>']);

        foreach ($projects as $project) {
            $table->addRow([
                sprintf('<fg=cyan>%s</>', $project->slug),
                sprintf('<fg=white>%s</>', $project->name),
                sprintf('<fg=gray>%s</>', $project->description ?: '—'),
            ]);
        }

        $table->render();
        $output->writeln('');

        return Command::SUCCESS;
    }
}
