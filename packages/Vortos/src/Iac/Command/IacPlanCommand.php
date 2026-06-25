<?php

declare(strict_types=1);

namespace Vortos\Iac\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Vortos\Iac\Lifecycle\IacExecutionContext;
use Vortos\Iac\Lifecycle\IacLifecycleService;
use Vortos\Iac\Lifecycle\IacWorkspace;

#[AsCommand(
    name: 'vortos:iac:plan',
    description: 'Generate a Terraform plan. Read-only — no infrastructure changes.',
)]
final class IacPlanCommand extends Command
{
    public function __construct(
        private readonly IacLifecycleService $lifecycle,
        private readonly string $workingDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('env', 'e', InputOption::VALUE_REQUIRED, 'Target environment', 'dev')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output plan as JSON summary')
            ->addOption('detailed-exitcode', null, InputOption::VALUE_NONE, 'Exit 1 when changes exist');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = (string) $input->getOption('env');

        try {
            $ws = IacWorkspace::forEnvironment($env, $this->workingDir);
            $ctx = new IacExecutionContext();

            $this->lifecycle->init($ws, $ctx);
            $plan = $this->lifecycle->plan($ws, $ctx);

            if ($input->getOption('json')) {
                $output->writeln((string) json_encode([
                    'has_changes' => $plan->hasChanges(),
                    'summary' => $plan->summary->toArray(),
                    'plan_file' => $plan->planFile,
                    'digest' => $plan->rawJsonDigest,
                ], JSON_PRETTY_PRINT));
            } else {
                $io->writeln($plan->toReviewableDiff());
            }

            if ($input->getOption('detailed-exitcode') && $plan->hasChanges()) {
                return 1;
            }

            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return 2;
        }
    }
}
