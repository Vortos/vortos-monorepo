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
    name: 'vortos:iac:destroy',
    description: 'Destroy all managed infrastructure for an environment.',
)]
final class IacDestroyCommand extends Command
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
            ->addOption('confirm', null, InputOption::VALUE_REQUIRED, 'Type the environment name to confirm destruction')
            ->addOption('force', null, InputOption::VALUE_NONE, 'Allow destruction of production environments')
            ->addOption('allow-destructive', null, InputOption::VALUE_NONE, 'Bypass blast-radius guard');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $env = (string) $input->getOption('env');
        $confirm = $input->getOption('confirm');
        $force = (bool) $input->getOption('force');

        $isProd = in_array($env, ['prod', 'production'], true);

        if ($isProd && !$force) {
            $io->error(sprintf(
                "Refusing to destroy production environment '%s' without --force.",
                $env,
            ));
            return 1;
        }

        if ($confirm !== $env) {
            $io->error(sprintf(
                "Confirmation mismatch: --confirm='%s' does not match environment '%s'.",
                (string) $confirm,
                $env,
            ));
            return 1;
        }

        try {
            $ws = IacWorkspace::forEnvironment($env, $this->workingDir);
            $ctx = new IacExecutionContext(
                allowDestructive: (bool) $input->getOption('allow-destructive'),
            );

            $this->lifecycle->init($ws, $ctx);

            $plan = $this->lifecycle->show($ws, $ctx);
            if ($plan->hasChanges()) {
                $io->writeln($plan->toReviewableDiff());
                $io->newLine();
            }

            $result = $this->lifecycle->destroy($ws, $ctx);

            if ($result->isSuccess()) {
                $io->success(sprintf('Destroyed %d resource(s) in %dms.', $result->destroyed, $result->durationMs));
                return Command::SUCCESS;
            }

            $io->error(sprintf('Destroy completed with %d failure(s).', $result->failed));
            return 2;
        } catch (\Throwable $e) {
            $io->error($e->getMessage());
            return 2;
        }
    }
}
