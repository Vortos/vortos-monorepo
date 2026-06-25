<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule;

use InvalidArgumentException;

/** A declared collection of {@see AlertRule}; duplicate ids are rejected eagerly. */
final class AlertRuleSet
{
    /** @var array<string, AlertRule> */
    private array $rules = [];

    /** @param list<AlertRule> $rules */
    public function __construct(array $rules = [])
    {
        foreach ($rules as $rule) {
            $this->add($rule);
        }
    }

    public function add(AlertRule $rule): void
    {
        if (isset($this->rules[$rule->id])) {
            throw new InvalidArgumentException(sprintf('Duplicate alert rule id "%s".', $rule->id));
        }

        $this->rules[$rule->id] = $rule;
    }

    public function has(string $id): bool
    {
        return isset($this->rules[$id]);
    }

    public function get(string $id): AlertRule
    {
        return $this->rules[$id] ?? throw new InvalidArgumentException(sprintf('Unknown alert rule "%s".', $id));
    }

    /** @return list<AlertRule> */
    public function all(): array
    {
        return array_values($this->rules);
    }
}
