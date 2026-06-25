<?php

declare(strict_types=1);

namespace Vortos\Deploy\Console;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\Deploy\Cutover\EdgeReconciler;

#[AsCommand(name: 'deploy:reconcile', description: 'Reconcile edge router state with desired release')]
final class ReconcileCommand extends Command
{
    public function __construct(
        private readonly EdgeReconciler $reconciler,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('env', null, InputOption::VALUE_REQUIRED, 'Target environment', 'production');
        $this->addOption('watch', null, InputOption::VALUE_NONE, 'Run in a loop');
        $this->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Watch interval in seconds', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env = (string) $input->getOption('env');
        $watch = (bool) $input->getOption('watch');
        $interval = (int) $input->getOption('interval');

        if (!$watch) {
            return $this->runOnce($env, $output);
        }

        $output->writeln(sprintf('Watching %s every %ds...', $env, $interval));

        /** @phpstan-ignore while.alwaysTrue */
        while (true) {
            $this->runOnce($env, $output);
            sleep($interval);
        }
    }

    private function runOnce(string $env, OutputInterface $output): int
    {
        $result = $this->reconciler->reconcile($env);

        if ($result->inSync) {
            $output->writeln(sprintf('[%s] in-sync: %s', $env, $result->detail));

            return Command::SUCCESS;
        }

        if ($result->skippedRateLimited) {
            $output->writeln(sprintf('[%s] rate-limited: %s', $env, $result->detail));

            return Command::SUCCESS;
        }

        if ($result->corrected) {
            $output->writeln(sprintf('[%s] corrected: %s', $env, $result->detail));

            return Command::SUCCESS;
        }

        $output->writeln(sprintf('[%s] drift detected: %s', $env, $result->detail));

        return Command::FAILURE;
    }
}
