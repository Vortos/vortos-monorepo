<?php

declare(strict_types=1);

namespace Vortos\Docker\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Docker\Worker\SupervisorFileManager;
use Vortos\Docker\Worker\WorkerProcessRegistry;

#[AsCommand(name: 'vortos:worker:install', description: 'Install managed supervisor blocks for Vortos workers')]
final class WorkerInstallCommand extends Command
{
    public function __construct(
        private readonly WorkerProcessRegistry $registry,
        private readonly SupervisorFileManager $manager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('worker', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Worker name to install. Omit to install all registered workers.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Supervisor config path relative to project root.', SupervisorFileManager::DEFAULT_PATH)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $selected = $this->registry->selected((array) $input->getOption('worker'));
            $result = $this->manager->install(
                (string) getcwd(),
                $selected,
                (bool) $input->getOption('dry-run'),
                (string) $input->getOption('path'),
            );
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        if (!$result->plan->hasChanges()) {
            $io->success('Supervisor worker config is already up to date.');
            return Command::SUCCESS;
        }

        $io->success(sprintf(
            'Supervisor worker config %s: %s',
            $input->getOption('dry-run') ? 'would be updated' : 'updated',
            $result->plan->path,
        ));

        return Command::SUCCESS;
    }
}
