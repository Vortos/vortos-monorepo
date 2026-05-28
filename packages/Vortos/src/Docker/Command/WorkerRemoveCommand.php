<?php

declare(strict_types=1);

namespace Vortos\Docker\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Docker\Worker\SupervisorFileManager;

#[AsCommand(name: 'vortos:worker:remove', description: 'Remove managed supervisor blocks for Vortos workers')]
final class WorkerRemoveCommand extends Command
{
    public function __construct(private readonly SupervisorFileManager $manager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('worker', InputArgument::IS_ARRAY | InputArgument::REQUIRED, 'Worker name(s) to remove.')
            ->addOption('path', null, InputOption::VALUE_REQUIRED, 'Supervisor config path relative to project root.', SupervisorFileManager::DEFAULT_PATH)
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Preview changes without writing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $result = $this->manager->remove(
            (string) getcwd(),
            (array) $input->getArgument('worker'),
            (bool) $input->getOption('dry-run'),
            (string) $input->getOption('path'),
        );

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
