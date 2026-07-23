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
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing.')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Fail (exit 1) if the committed config does not match the registered workers. For CI.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $selected = $this->registry->selected((array) $input->getOption('worker'));

            if ((bool) $input->getOption('check')) {
                return $this->check($io, $selected, (string) $input->getOption('path'));
            }

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

    /**
     * The gate. `--dry-run` exits 0 whether or not changes are pending, so it can report drift but
     * can never stop a build — which is how a worker registered in code reached no supervisor
     * program and nobody noticed until the queue it drains had silently backed up. `--check` turns
     * the same plan into a failed build, and names the workers so the fix is a copy-paste.
     */
    private function check(SymfonyStyle $io, WorkerProcessRegistry $selected, string $path): int
    {
        $drift = $this->manager->drift((string) getcwd(), $selected, $path);
        $missing = $drift['missing'];
        $stale = $drift['stale'];

        if ($missing === [] && $stale === []) {
            $io->success(sprintf('%s matches the registered workers.', $path));

            return Command::SUCCESS;
        }

        if ($missing !== []) {
            $io->error(sprintf(
                "%s has no supervisor program for: %s\nThese workers are registered but would never start.",
                $path,
                implode(', ', $missing),
            ));
        }

        if ($stale !== []) {
            $io->error(sprintf(
                '%s has out-of-date blocks for: %s',
                $path,
                implode(', ', $stale),
            ));
        }

        $io->writeln(sprintf('Fix: php bin/console vortos:worker:install --path=%s', $path));

        return Command::FAILURE;
    }
}
