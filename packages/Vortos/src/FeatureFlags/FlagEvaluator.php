<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags;

final class FlagEvaluator
{
    public function evaluate(FeatureFlag $flag, FlagContext $context): bool
    {
        if (!$flag->enabled) {
            return false;
        }

        if (empty($flag->rules)) {
            return true;
        }

        foreach ($flag->rules as $rule) {
            if ($this->matchesRule($rule, $flag->name, $context)) {
                return true;
            }
        }

        return false;
    }

    public function evaluateVariant(FeatureFlag $flag, FlagContext $context): string
    {
        if (!$flag->enabled || empty($flag->variants) || $context->userId === null) {
            return 'control';
        }

        $bucket     = $this->stableBucket($flag->name, $context->userId);
        $cumulative = 0;

        foreach ($flag->variants as $variant => $percentage) {
            $cumulative += $percentage;
            if ($bucket < $cumulative) {
                return $variant;
            }
        }

        return 'control';
    }

    private function matchesRule(FlagRule $rule, string $flagName, FlagContext $context): bool
    {
        return match ($rule->type) {
            FlagRule::TYPE_USERS      => $this->matchesUsers($rule, $context),
            FlagRule::TYPE_ATTRIBUTE  => $this->matchesAttribute($rule, $context),
            FlagRule::TYPE_PERCENTAGE => $this->matchesPercentage($rule, $flagName, $context),
            default                   => false,
        };
    }

    private function matchesUsers(FlagRule $rule, FlagContext $context): bool
    {
        return $context->userId !== null && in_array($context->userId, $rule->users, true);
    }

    private function matchesPercentage(FlagRule $rule, string $flagName, FlagContext $context): bool
    {
        if ($context->userId === null) {
            return false;
        }

        return $this->stableBucket($flagName, $context->userId) < $rule->percentage;
    }

    private function matchesAttribute(FlagRule $rule, FlagContext $context): bool
    {
        $actual = $context->getAttribute($rule->attribute ?? '');

        if ($actual === null) {
            return false;
        }

        return match ($rule->operator) {
            FlagRule::OP_EQUALS     => $actual === $rule->value,
            FlagRule::OP_NOT_EQUALS => $actual !== $rule->value,
            FlagRule::OP_IN         => in_array($actual, (array) $rule->value, true),
            FlagRule::OP_NOT_IN     => !in_array($actual, (array) $rule->value, true),
            FlagRule::OP_CONTAINS   => str_contains((string) $actual, (string) $rule->value),
            default                 => false,
        };
    }

    // Deterministic 0-99 bucket — same user always lands in the same bucket for a given flag.
    private function stableBucket(string $flagName, string $userId): int
    {
        return abs(crc32($flagName . $userId)) % 100;
    }
}
