<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

/**
 * The bucket lifecycle configuration before and after applying the declared rules, and the per-rule
 * changes between them.
 *
 * A bucket holds one lifecycle document, so apply always writes `desired` whole. That is why the plan
 * carries the unmanaged rules too: they are part of what gets written, unchanged.
 */
final class LifecyclePlan
{
    /** @param list<LifecycleRuleChange> $changes one entry per managed rule, including unchanged ones */
    public function __construct(
        private readonly LifecycleConfiguration $current,
        private readonly LifecycleConfiguration $desired,
        private readonly array $changes,
    ) {}

    public function current(): LifecycleConfiguration
    {
        return $this->current;
    }

    public function desired(): LifecycleConfiguration
    {
        return $this->desired;
    }

    /** @return list<LifecycleRuleChange> */
    public function changes(): array
    {
        return $this->changes;
    }

    public function change(string $ruleId): ?LifecycleRuleChange
    {
        foreach ($this->changes as $change) {
            if ($change->ruleId() === $ruleId) {
                return $change;
            }
        }

        return null;
    }

    public function hasChanges(): bool
    {
        foreach ($this->changes as $change) {
            if ($change->change() !== LifecyclePlanChange::None) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'has_changes' => $this->hasChanges(),
            'current_rule_count' => count($this->current->rules()),
            'desired_rule_count' => count($this->desired->rules()),
            'changes' => array_map(static fn(LifecycleRuleChange $c): array => $c->toArray(), $this->changes),
        ];
    }
}
