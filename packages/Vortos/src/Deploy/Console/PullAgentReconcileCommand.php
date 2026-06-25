<?php

declare(strict_types=1);

namespace Vortos\Deploy\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Deploy\PullAgent\PullAgentReconciler;

#[AsCommand(
    name: 'deploy:agent',
    description: 'Pull-agent one-shot reconcile: fetch, verify, and apply the latest desired-state manifest',
)]
final class PullAgentReconcileCommand extends Command
{
    public function __construct(
        private readonly PullAgentReconciler $reconciler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('env', InputArgument::REQUIRED, 'The environment to reconcile');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = (string) $input->getArgument('env');

        try {
            $result = $this->reconciler->reconcile($env);
        } catch (\Throwable $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        if ($result->applied) {
            $io->success(sprintf('Applied: %s', $result->detail ?? 'done'));

            return Command::SUCCESS;
        }

        if ($result->alreadyCurrent) {
            $io->info(sprintf('Already current: %s', $result->detail ?? 'no change needed'));

            return Command::SUCCESS;
        }

        $io->warning(sprintf('Not applied: %s', $result->detail ?? 'unknown'));

        return Command::SUCCESS;
    }
}
