<?php

declare(strict_types=1);

namespace Vortos\Config\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Config\Service\ConfigFilePublisher;

#[AsCommand(
    name: 'vortos:config:publish',
    description: 'Publish config stubs to your project\'s config/ directory',
)]
final class PublishConfigCommand extends Command
{
    public function __construct(private readonly ConfigFilePublisher $publisher)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'module',
                'm',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Module(s) to publish (e.g. --module=cache --module=auth). Omit to publish all.',
            )
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing config files')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview files without writing them');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var string[] $modules */
        $modules = $input->getOption('module');
        $force = (bool) $input->getOption('force');
        $dryRun = (bool) $input->getOption('dry-run');
        $projectDir = (string) getcwd();

        if ($dryRun) {
            $io->note('Dry run — no files will be written.');
        }

        $result = $this->publisher->publish($projectDir, $modules, $force, $dryRun);

        if ($result->unknown !== []) {
            $io->error(sprintf(
                'Unknown module(s): %s. Available: %s',
                implode(', ', $result->unknown),
                implode(', ', $this->publisher->available()),
            ));

            return Command::FAILURE;
        }

        if ($result->published !== []) {
            $io->success(sprintf(
                '%s config file(s) %s:',
                count($result->published),
                $dryRun ? 'would be published' : 'published',
            ));
            $io->listing($result->published);
        }

        if ($result->skipped !== []) {
            $io->section('Skipped (already exist — use --force to overwrite)');
            $io->listing($result->skipped);
        }

        if ($result->published === [] && $result->skipped === []) {
            $io->info('Nothing to publish.');
        }

        if (!$dryRun && $result->published !== []) {
            $io->note('Review each file and adjust values for your environment before deploying.');
        }

        return Command::SUCCESS;
    }
}
