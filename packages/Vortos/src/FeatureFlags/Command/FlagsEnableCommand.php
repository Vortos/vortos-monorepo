<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\Application\FlagWriteService;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

#[AsCommand(name: 'vortos:flags:enable', description: 'Enable a feature flag (optionally with a rollout percentage)')]
final class FlagsEnableCommand extends Command
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly FlagWriteService $writeService,
        private readonly FlagScopeContext $scope = new FlagScopeContext(),
        private readonly ProjectContext $projectContext = new ProjectContext(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Flag name')
            ->addOption('rollout', null, InputOption::VALUE_REQUIRED, 'Percentage rollout 1–100 (omit for full rollout)')
            ->addOption('env',     null, InputOption::VALUE_REQUIRED, 'Target environment (default: production)', FlagScopeContext::ENV_PRODUCTION)
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'Project slug (default: default)', ProjectContext::DEFAULT_PROJECT);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $env     = (string) ($input->getOption('env') ?? FlagScopeContext::ENV_PRODUCTION);
        $project = (string) ($input->getOption('project') ?? ProjectContext::DEFAULT_PROJECT);
        $this->scope->withEnvironment($env);
        $this->projectContext->withProject($project);

        $name = (string) $input->getArgument('name');
        $flag = $this->storage->findByName($name);

        if ($flag === null) {
            $output->writeln(sprintf('<error>Flag "%s" not found.</error>', $name));
            return Command::FAILURE;
        }

        $rollout = $input->getOption('rollout');
        $rules   = null;

        if ($rollout !== null) {
            $pct = (int) $rollout;

            if ($pct < 1 || $pct > 100) {
                $output->writeln('<error>--rollout must be between 1 and 100.</error>');
                return Command::FAILURE;
            }

            $rules = array_values(array_filter($flag->rules, fn($r) => $r->type !== FlagRule::TYPE_PERCENTAGE));
            if ($pct < 100) {
                $rules[] = new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: $pct);
            }
        }

        $this->writeService->enable($name, 'cli', null, $rules);

        $suffix = $rollout !== null && (int) $rollout < 100
            ? sprintf(' <fg=cyan>(%d%% rollout)</>', (int) $rollout)
            : ' <fg=gray>(100% — all users)</>';

        $output->writeln(sprintf('  <info>enabled:</info> %s%s', $name, $suffix));

        return Command::SUCCESS;
    }
}
