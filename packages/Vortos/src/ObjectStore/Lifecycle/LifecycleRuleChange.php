<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\Lifecycle;

/** What a plan will do to one managed rule, with both sides so an operator can see the difference. */
final class LifecycleRuleChange
{
    /**
     * @param array<string, mixed>|null $current the rule as the provider holds it now
     * @param array<string, mixed>|null $desired the rule as it will be written
     */
    public function __construct(
        private readonly string $ruleId,
        private readonly LifecyclePlanChange $change,
        private readonly ?array $current,
        private readonly ?array $desired,
    ) {}

    public function ruleId(): string
    {
        return $this->ruleId;
    }

    public function change(): LifecyclePlanChange
    {
        return $this->change;
    }

    /** @return array<string, mixed>|null */
    public function current(): ?array
    {
        return $this->current;
    }

    /** @return array<string, mixed>|null */
    public function desired(): ?array
    {
        return $this->desired;
    }

    /** @return array{rule_id: string, change: string, current: array<string, mixed>|null, desired: array<string, mixed>|null} */
    public function toArray(): array
    {
        return [
            'rule_id' => $this->ruleId,
            'change' => $this->change->value,
            'current' => $this->current,
            'desired' => $this->desired,
        ];
    }
}
