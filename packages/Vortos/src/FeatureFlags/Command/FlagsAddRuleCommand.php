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
use Vortos\FeatureFlags\Exception\InvalidFlagException;
use Vortos\FeatureFlags\FlagEvaluator;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\FlagScopeContext;
use Vortos\FeatureFlags\ProjectContext;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlags\Validation\FlagValidator;

#[AsCommand(name: 'vortos:flags:add-rule', description: 'Add a targeting rule to a flag')]
final class FlagsAddRuleCommand extends Command
{
    public function __construct(
        private readonly FlagStorageInterface $storage,
        private readonly FlagValidator $validator,
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
            ->addOption('type',       null, InputOption::VALUE_REQUIRED, 'Rule type: users | attribute | percentage')
            ->addOption('users',      null, InputOption::VALUE_REQUIRED, 'Comma-separated user IDs (for type=users)')
            ->addOption('attribute',  null, InputOption::VALUE_REQUIRED, 'Attribute key (for type=attribute)')
            ->addOption('operator',   null, InputOption::VALUE_REQUIRED, 'Operator (for type=attribute): ' . implode('|', FlagRule::ATTRIBUTE_OPERATORS))
            ->addOption('value',      null, InputOption::VALUE_REQUIRED, 'Attribute value, or comma-separated list for in/not_in (for type=attribute)')
            ->addOption('zone',       null, InputOption::VALUE_REQUIRED, 'Trust zone for attribute rule: any | trusted | untrusted', FlagRule::ZONE_ANY)
            ->addOption('percentage', null, InputOption::VALUE_REQUIRED, 'Rollout percentage 1–100 (for type=percentage)')
            ->addOption('json',       null, InputOption::VALUE_REQUIRED, 'A full rule as JSON (supports nested AND/OR groups). Overrides other options.')
            ->addOption('clear',      null, InputOption::VALUE_NONE, 'Remove all existing rules before adding this one')
            ->addOption('env',        null, InputOption::VALUE_REQUIRED, 'Target environment (default: production)', FlagScopeContext::ENV_PRODUCTION)
            ->addOption('project',    null, InputOption::VALUE_REQUIRED, 'Project slug (default: default)', ProjectContext::DEFAULT_PROJECT);
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

        $jsonRule = $input->getOption('json');
        if ($jsonRule !== null) {
            $rule = $this->buildFromJson((string) $jsonRule, $output);
        } else {
            $type = $input->getOption('type');

            if (!in_array($type, [FlagRule::TYPE_USERS, FlagRule::TYPE_ATTRIBUTE, FlagRule::TYPE_PERCENTAGE], true)) {
                $output->writeln('<error>--type must be one of: users, attribute, percentage (or use --json for groups)</error>');
                return Command::FAILURE;
            }

            $rule = $this->buildRule($type, $input, $output);
        }

        if ($rule === null) {
            return Command::FAILURE;
        }

        $rules = $input->getOption('clear') ? [] : $flag->rules;
        $rules[] = $rule;

        $updated = $flag->withRules($rules);

        try {
            $this->validator->validate($updated);
        } catch (InvalidFlagException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));
            return Command::FAILURE;
        }

        $this->writeService->changeRules($name, $rules, 'cli');

        $output->writeln(sprintf('  <info>rule added:</info> %s → %s', $name, $this->describe($rule)));

        return Command::SUCCESS;
    }

    private function buildRule(string $type, InputInterface $input, OutputInterface $output): ?FlagRule
    {
        switch ($type) {
            case FlagRule::TYPE_USERS:
                $raw = $input->getOption('users');
                if (!$raw) {
                    $output->writeln('<error>--users is required for type=users</error>');
                    return null;
                }
                return new FlagRule(type: FlagRule::TYPE_USERS, users: array_map('trim', explode(',', $raw)));

            case FlagRule::TYPE_ATTRIBUTE:
                $attr     = $input->getOption('attribute');
                $operator = $input->getOption('operator') ?? FlagRule::OP_EQUALS;
                $value    = $input->getOption('value');
                $zone     = (string) $input->getOption('zone');

                if (!in_array($operator, FlagRule::ATTRIBUTE_OPERATORS, true)) {
                    $output->writeln(sprintf('<error>--operator must be one of: %s</error>', implode(', ', FlagRule::ATTRIBUTE_OPERATORS)));
                    return null;
                }

                if (!in_array($zone, [FlagRule::ZONE_ANY, FlagRule::ZONE_TRUSTED, FlagRule::ZONE_UNTRUSTED], true)) {
                    $output->writeln('<error>--zone must be one of: any, trusted, untrusted</error>');
                    return null;
                }

                if (!$attr || ($operator !== FlagRule::OP_EXISTS && $value === null)) {
                    $output->writeln('<error>--attribute (and --value, except for exists) are required for type=attribute</error>');
                    return null;
                }

                $value = in_array($operator, [FlagRule::OP_IN, FlagRule::OP_NOT_IN], true)
                    ? array_map('trim', explode(',', (string) $value))
                    : $value;

                return new FlagRule(
                    type:      FlagRule::TYPE_ATTRIBUTE,
                    attribute: $attr,
                    operator:  $operator,
                    value:     $value,
                    zone:      $zone,
                );

            case FlagRule::TYPE_PERCENTAGE:
                $pct = (int) ($input->getOption('percentage') ?? 0);
                if ($pct < 1 || $pct > 100) {
                    $output->writeln('<error>--percentage must be between 1 and 100</error>');
                    return null;
                }
                return new FlagRule(type: FlagRule::TYPE_PERCENTAGE, percentage: $pct);
        }

        return null;
    }

    private function buildFromJson(string $json, OutputInterface $output): ?FlagRule
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $output->writeln(sprintf('<error>Invalid --json: %s</error>', $e->getMessage()));
            return null;
        }

        if (!is_array($data) || !isset($data['type'])) {
            $output->writeln('<error>--json must be an object with at least a "type" field.</error>');
            return null;
        }

        try {
            $rule = FlagRule::fromArray($data);
        } catch (\Throwable $e) {
            $output->writeln(sprintf('<error>Could not build rule from JSON: %s</error>', $e->getMessage()));
            return null;
        }

        if ($this->groupDepth($rule) > FlagEvaluator::MAX_GROUP_DEPTH) {
            $output->writeln(sprintf('<error>Rule nesting exceeds max depth of %d.</error>', FlagEvaluator::MAX_GROUP_DEPTH));
            return null;
        }

        return $rule;
    }

    private function groupDepth(FlagRule $rule, int $depth = 1): int
    {
        if ($rule->type !== FlagRule::TYPE_GROUP || $rule->children === []) {
            return $depth;
        }

        $max = $depth;
        foreach ($rule->children as $child) {
            $max = max($max, $this->groupDepth($child, $depth + 1));
        }

        return $max;
    }

    private function describe(FlagRule $rule): string
    {
        return match ($rule->type) {
            FlagRule::TYPE_USERS      => sprintf('users in [%s]', implode(', ', $rule->users)),
            FlagRule::TYPE_PERCENTAGE => sprintf('%d%% rollout', $rule->percentage),
            FlagRule::TYPE_ATTRIBUTE  => sprintf('%s %s %s', $rule->attribute, $rule->operator, is_array($rule->value) ? '[' . implode(',', $rule->value) . ']' : $rule->value),
            FlagRule::TYPE_GROUP      => sprintf('%s group of %d rule(s)', strtoupper((string) $rule->combinator), count($rule->children)),
            default                   => $rule->type,
        };
    }
}
