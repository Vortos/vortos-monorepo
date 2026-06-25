<?php

declare(strict_types=1);

namespace Vortos\Iac\Lifecycle;

final readonly class IacPlanSummary
{
    public function __construct(
        public int $add,
        public int $change,
        public int $destroy,
        public int $replace,
    ) {
        if ($add < 0 || $change < 0 || $destroy < 0 || $replace < 0) {
            throw new \InvalidArgumentException('Plan summary counts must be non-negative.');
        }
    }

    public function total(): int
    {
        return $this->add + $this->change + $this->destroy + $this->replace;
    }

    public function hasChanges(): bool
    {
        return $this->total() > 0;
    }

    public function destructiveCount(): int
    {
        return $this->destroy + $this->replace;
    }

    public function isDestructive(): bool
    {
        return $this->destructiveCount() > 0;
    }

    /** @return array{add: int, change: int, destroy: int, replace: int} */
    public function toArray(): array
    {
        return [
            'add' => $this->add,
            'change' => $this->change,
            'destroy' => $this->destroy,
            'replace' => $this->replace,
        ];
    }
}
