<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

final class LifecyclePlan
{
    public function __construct(
        private readonly LifecycleConfiguration $current,
        private readonly LifecycleConfiguration $desired,
        private readonly LifecyclePlanChange $change,
        private readonly string $managedRuleId,
    ) {}

    public function current(): LifecycleConfiguration
    {
        return $this->current;
    }

    public function desired(): LifecycleConfiguration
    {
        return $this->desired;
    }

    public function change(): LifecyclePlanChange
    {
        return $this->change;
    }

    public function managedRuleId(): string
    {
        return $this->managedRuleId;
    }

    public function hasChanges(): bool
    {
        return $this->change !== LifecyclePlanChange::None;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'change' => $this->change->value,
            'managed_rule_id' => $this->managedRuleId,
            'current_rule_count' => count($this->current->rules()),
            'desired_rule_count' => count($this->desired->rules()),
            'current_managed_rule' => $this->current->rule($this->managedRuleId),
            'desired_managed_rule' => $this->desired->rule($this->managedRuleId),
        ];
    }
}
