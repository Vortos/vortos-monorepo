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
    name: 'vortos:iac:apply',
    description: 'Apply a Terraform plan to provision infrastructure.',
)]
final class IacApplyCommand extends Command
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
            ->addOption('allow-destructive', null, InputOption::VALUE_NONE, 'Allow destructive changes beyond the blast-radius limit')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Skip confirmation prompt');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = (string) $input->getOption('env');
        $allowDestructive = (bool) $input->getOption('allow-destructive');

        try {
            $ws = IacWorkspace::forEnvironment($env, $this->workingDir);
            $ctx = new IacExecutionContext(allowDestructive: $allowDestructive);

            $this->lifecycle->init($ws, $ctx);
            $plan = $this->lifecycle->plan($ws, $ctx);

            if (!$plan->hasChanges()) {
                $io->success('No changes. Infrastructure is up-to-date.');
                return Command::SUCCESS;
            }

            $io->writeln($plan->toReviewableDiff());
            $io->newLine();

            $isProd = in_array($env, ['prod', 'production'], true);

            if (!$input->getOption('yes')) {
                $confirmMessage = $isProd
                    ? sprintf('Apply these changes to %s? Type the environment name to confirm:', $env)
                    : 'Apply these changes?';

                if ($isProd) {
                    $typed = $io->ask($confirmMessage);
                    if ($typed !== $env) {
                        $io->error('Confirmation mismatch. Aborting.');
                        return 1;
                    }
                } elseif (!$io->confirm($confirmMessage, false)) {
                    $io->warning('Aborted.');
                    return 1;
                }
            }

            $result = $this->lifecycle->apply($ws, $plan, $ctx);

            if ($result->isSuccess()) {
                $io->success(sprintf('Applied %d resource(s) in %dms.', $result->applied, $result->durationMs));
                return Command::SUCCESS;
            }

            $io->error(sprintf('Apply completed with %d failure(s).', $result->failed));
            return 2;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return 2;
        }
    }
}
