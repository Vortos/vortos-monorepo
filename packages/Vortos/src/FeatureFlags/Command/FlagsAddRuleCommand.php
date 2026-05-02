<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Vortos\FeatureFlags\FlagRule;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;

#[AsCommand(name: 'vortos:flags:add-rule', description: 'Add a targeting rule to a flag')]
final class FlagsAddRuleCommand extends Command
{
    public function __construct(private readonly FlagStorageInterface $storage)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Flag name')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'Rule type: users | attribute | percentage')
            ->addOption('users', null, InputOption::VALUE_REQUIRED, 'Comma-separated user IDs (for type=users)')
            ->addOption('attribute', null, InputOption::VALUE_REQUIRED, 'Attribute key (for type=attribute)')
            ->addOption('operator', null, InputOption::VALUE_REQUIRED, 'Operator: equals|not_equals|in|not_in|contains (for type=attribute)')
            ->addOption('value', null, InputOption::VALUE_REQUIRED, 'Attribute value, or comma-separated list for in/not_in (for type=attribute)')
            ->addOption('percentage', null, InputOption::VALUE_REQUIRED, 'Rollout percentage 1–100 (for type=percentage)')
            ->addOption('clear', null, InputOption::VALUE_NONE, 'Remove all existing rules before adding this one');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $name = (string) $input->getArgument('name');
        $flag = $this->storage->findByName($name);

        if ($flag === null) {
            $output->writeln(sprintf('<error>Flag "%s" not found.</error>', $name));
            return Command::FAILURE;
        }

        $type = $input->getOption('type');

        if (!in_array($type, [FlagRule::TYPE_USERS, FlagRule::TYPE_ATTRIBUTE, FlagRule::TYPE_PERCENTAGE], true)) {
            $output->writeln('<error>--type must be one of: users, attribute, percentage</error>');
            return Command::FAILURE;
        }

        $rule = $this->buildRule($type, $input, $output);

        if ($rule === null) {
            return Command::FAILURE;
        }

        $rules = $input->getOption('clear') ? [] : $flag->rules;
        $rules[] = $rule;

        $this->storage->save($flag->withRules($rules));

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

                if (!$attr || $value === null) {
                    $output->writeln('<error>--attribute and --value are required for type=attribute</error>');
                    return null;
                }

                $value = in_array($operator, [FlagRule::OP_IN, FlagRule::OP_NOT_IN], true)
                    ? array_map('trim', explode(',', $value))
                    : $value;

                return new FlagRule(
                    type:      FlagRule::TYPE_ATTRIBUTE,
                    attribute: $attr,
                    operator:  $operator,
                    value:     $value,
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

    private function describe(FlagRule $rule): string
    {
        return match ($rule->type) {
            FlagRule::TYPE_USERS      => sprintf('users in [%s]', implode(', ', $rule->users)),
            FlagRule::TYPE_PERCENTAGE => sprintf('%d%% rollout', $rule->percentage),
            FlagRule::TYPE_ATTRIBUTE  => sprintf('%s %s %s', $rule->attribute, $rule->operator, is_array($rule->value) ? '[' . implode(',', $rule->value) . ']' : $rule->value),
            default                   => $rule->type,
        };
    }
}
