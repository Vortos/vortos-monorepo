<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

#[AsCommand(name: 'vortos:flags:show', description: 'Show full details of a feature flag including all targeting rules')]
final class FlagsShowCommand extends Command
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly FlagScopeContext $scope = new FlagScopeContext(),
        private readonly ProjectContext $projectContext = new ProjectContext(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Flag name')
            ->addOption('json',    null, InputOption::VALUE_NONE, 'Output as JSON')
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

        if ($input->getOption('json')) {
            $output->writeln(json_encode($flag->toArray(), JSON_PRETTY_PRINT));
            return Command::SUCCESS;
        }

        $this->render($output, $flag);

        return Command::SUCCESS;
    }

    private function render(OutputInterface $output, FeatureFlag $flag): void
    {
        $divider  = ' <fg=gray>' . str_repeat('─', 54) . '</>';
        $statusFg = $flag->enabled ? 'green' : 'red';
        $statusLabel = $flag->enabled ? 'ENABLED' : 'DISABLED';

        $output->writeln('');
        $output->writeln(sprintf(' <fg=gray>Flag</>        <fg=white;options=bold>%s</>', $flag->name));
        $output->writeln(sprintf(' <fg=gray>Env</>         <fg=cyan>%s</>', $flag->environment));
        $output->writeln(sprintf(' <fg=gray>Project</>     <fg=cyan>%s</>', $flag->projectId));
        $output->writeln(sprintf(' <fg=gray>Status</>      <fg=%s>%s</>', $statusFg, $statusLabel));
        $output->writeln(sprintf(' <fg=gray>Description</>  %s', $flag->description ?: '<fg=gray>(none)</>'));
        $output->writeln(sprintf(
            ' <fg=gray>Updated</>      %s',
            $flag->updatedAt->format('Y-m-d H:i:s') . ' UTC',
        ));

        if ($flag->isVariant() && $flag->variants !== null) {
            $output->writeln('');
            $output->writeln(sprintf(' <fg=white;options=bold>Variants</> <fg=gray>(%d)</>', count($flag->variants)));
            $output->writeln($divider);
            foreach ($flag->variants as $variantName => $pct) {
                $output->writeln(sprintf('  <fg=cyan>%-14s</> <fg=white>%d%%</>', $variantName, $pct));
            }
        }

        $rules = $flag->rules;

        $output->writeln('');

        if (empty($rules)) {
            $output->writeln($divider);
            $output->writeln(sprintf(
                '  <fg=gray>No rules configured — flag evaluates globally as</> <fg=%s>%s</>.',
                $statusFg,
                $statusLabel,
            ));
            $output->writeln($divider);
            $output->writeln('');
            return;
        }

        $output->writeln(sprintf(' <fg=white;options=bold>Rules</> <fg=gray>(%d)</>', count($rules)));
        $output->writeln($divider);

        foreach ($rules as $i => $rule) {
            $output->writeln('');
            $output->writeln(sprintf('  <fg=gray>[%d]</> <fg=cyan>type:</> %s', $i + 1, $rule->type));

            match ($rule->type) {
                FlagRule::TYPE_USERS      => $this->renderUsersRule($output, $rule),
                FlagRule::TYPE_ATTRIBUTE  => $this->renderAttributeRule($output, $rule),
                FlagRule::TYPE_PERCENTAGE => $this->renderPercentageRule($output, $rule),
                default                   => null,
            };
        }

        $output->writeln('');
        $output->writeln($divider);
        $output->writeln(sprintf(
            '  <fg=gray>Evaluation:</> <fg=%s>%s</> <fg=gray>for a request when ANY rule matches.</>',
            $statusFg,
            $statusLabel,
        ));
        $output->writeln($divider);
        $output->writeln('');
    }

    private function renderUsersRule(OutputInterface $output, FlagRule $rule): void
    {
        $users = $rule->users;
        $first = array_shift($users);
        $output->writeln(sprintf('      <fg=gray>users:</>   %s', $first));
        foreach ($users as $userId) {
            $output->writeln(sprintf('               %s', $userId));
        }
    }

    private function renderAttributeRule(OutputInterface $output, FlagRule $rule): void
    {
        $value = is_array($rule->value)
            ? '[' . implode(', ', $rule->value) . ']'
            : (string) $rule->value;

        $output->writeln(sprintf('      <fg=gray>attribute:</>  %s', $rule->attribute));
        $output->writeln(sprintf('      <fg=gray>operator:</>   %s', $rule->operator));
        $output->writeln(sprintf('      <fg=gray>value:</>      %s', $value));
    }

    private function renderPercentageRule(OutputInterface $output, FlagRule $rule): void
    {
        $output->writeln(sprintf('      <fg=gray>rollout:</>   <fg=white>%d%%</>', $rule->percentage));
    }
}
