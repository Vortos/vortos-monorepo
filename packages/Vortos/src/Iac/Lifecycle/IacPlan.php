<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

final readonly class IacPlan
{
    /**
     * @param list<IacResourceChange> $resourceChanges
     */
    public function __construct(
        public IacPlanSummary $summary,
        public array $resourceChanges,
        public string $planFile,
        public string $rawJsonDigest,
        public string $planFileDigest,
    ) {}

    public function hasChanges(): bool
    {
        return $this->summary->hasChanges();
    }

    public function isDestructive(): bool
    {
        return $this->summary->isDestructive();
    }

    public function destructiveCount(): int
    {
        return $this->summary->destructiveCount();
    }

    public function toReviewableDiff(): string
    {
        if (!$this->hasChanges()) {
            return 'No changes. Infrastructure is up-to-date.';
        }

        $lines = [];
        $lines[] = sprintf(
            'Plan: %d to add, %d to change, %d to destroy, %d to replace.',
            $this->summary->add,
            $this->summary->change,
            $this->summary->destroy,
            $this->summary->replace,
        );
        $lines[] = '';

        foreach ($this->resourceChanges as $change) {
            $symbol = match ($change->action) {
                IacChangeAction::Create => '+',
                IacChangeAction::Update => '~',
                IacChangeAction::Delete => '-',
                IacChangeAction::Replace => '-/+',
                IacChangeAction::NoOp, IacChangeAction::Read => null,
            };

            if ($symbol === null) {
                continue;
            }

            $lines[] = sprintf('  %s %s (%s)', $symbol, $change->address, $change->action->value);
        }

        return implode("\n", $lines);
    }
}
