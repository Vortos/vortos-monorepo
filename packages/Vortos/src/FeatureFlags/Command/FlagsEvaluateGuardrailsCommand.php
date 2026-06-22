<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Guardrail\GuardrailWatcherService;

#[AsCommand(
    name: 'vortos:flags:guardrails:evaluate',
    description: 'Evaluate release guardrails and auto-rollback breached flags (Block 15)',
)]
final class FlagsEvaluateGuardrailsCommand extends Command
{
    public function __construct(
        private readonly GuardrailWatcherService $watcher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project id (informational)', 'default')
            ->addOption('env', null, InputOption::VALUE_REQUIRED, 'Environment (informational)', 'production');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $evaluated = $this->watcher->evaluate();

        $output->writeln(sprintf('  <info>evaluated:</info> %d guardrail policy/policies', $evaluated));

        return Command::SUCCESS;
    }
}
