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
            ->addOption('path', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Supervisor config path relative to project root. Repeatable with --check: a worker satisfies the check by appearing in ANY of the given configs.', [SupervisorFileManager::DEFAULT_PATH])
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing.')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Fail (exit 1) if the committed config does not match the registered workers. For CI.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $selected = $this->registry->selected((array) $input->getOption('worker'));
            /** @var list<string> $paths */
            $paths = array_values(array_filter((array) $input->getOption('path')));

            if ((bool) $input->getOption('check')) {
                return $this->check($io, $selected, $paths);
            }

            if (\count($paths) > 1) {
                $io->error('--path is only repeatable with --check; installing needs exactly one target config.');

                return Command::FAILURE;
            }

            $result = $this->manager->install(
                (string) getcwd(),
                $selected,
                (bool) $input->getOption('dry-run'),
                $paths[0] ?? SupervisorFileManager::DEFAULT_PATH,
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
     *
     * Coverage, not per-file completeness. Workers are placed across containers on purpose — the
     * scheduler daemon belongs on exactly ONE node, so demanding it in every supervisor config would
     * push a second scheduler onto the worker color. A worker is only missing when it appears in
     * NONE of the given configs; staleness is still reported per file, since a diverged block is
     * about the file that holds it.
     *
     * @param list<string> $paths
     */
    private function check(SymfonyStyle $io, WorkerProcessRegistry $selected, array $paths): int
    {
        $paths = $paths === [] ? [SupervisorFileManager::DEFAULT_PATH] : $paths;
        $cwd = (string) getcwd();

        $placed = [];
        $staleByPath = [];

        foreach ($paths as $path) {
            $drift = $this->manager->drift($cwd, $selected, $path);

            if ($drift['stale'] !== []) {
                $staleByPath[$path] = $drift['stale'];
            }

            foreach ($selected->all() as $definition) {
                if (!\in_array($definition->name, $drift['missing'], true)) {
                    $placed[$definition->name] = true;
                }
            }
        }

        $unplaced = [];
        foreach ($selected->all() as $definition) {
            if (!isset($placed[$definition->name])) {
                $unplaced[] = $definition->name;
            }
        }

        if ($unplaced === [] && $staleByPath === []) {
            $io->success(sprintf(
                'All %d registered worker(s) are placed in: %s',
                \count($selected->all()),
                implode(', ', $paths),
            ));

            return Command::SUCCESS;
        }

        if ($unplaced !== []) {
            $io->error(sprintf(
                "No supervisor config contains a program for: %s\nThese workers are registered but "
                . "would never start. Configs checked: %s",
                implode(', ', $unplaced),
                implode(', ', $paths),
            ));
        }

        foreach ($staleByPath as $path => $stale) {
            $io->error(sprintf('%s has out-of-date blocks for: %s', $path, implode(', ', $stale)));
        }

        $io->writeln(sprintf(
            'Fix: php bin/console vortos:worker:install --path=%s [--worker=<name>]',
            $paths[0],
        ));

        return Command::FAILURE;
    }
}
