<?php

declare(strict_types=1);

namespace Vortos\Release\Plan;

use Vortos\Release\Version\BumpLevel;

final readonly class ReleasePlan
{
    /**
     * @param list<PackageTagPlan> $packages
     */
    public function __construct(
        public string $txId,
        public \DateTimeImmutable $createdAt,
        public array $packages,
        public BumpLevel $globalBump,
        public bool $skewDetected,
    ) {}

    public function hasChanges(): bool
    {
        foreach ($this->packages as $plan) {
            if ($plan->hasChanges()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<PackageTagPlan> */
    public function packagesWithChanges(): array
    {
        return array_values(array_filter(
            $this->packages,
            static fn (PackageTagPlan $p) => $p->hasChanges(),
        ));
    }

    public function render(): string
    {
        $lines = [];
        $lines[] = sprintf('Release Plan [%s] — %s', $this->txId, $this->createdAt->format('Y-m-d H:i:s'));
        $lines[] = sprintf('Global bump: %s | Skew: %s', $this->globalBump->value, $this->skewDetected ? 'DETECTED' : 'none');
        $lines[] = '';

        $changed = $this->packagesWithChanges();
        $unchanged = array_filter($this->packages, static fn (PackageTagPlan $p) => !$p->hasChanges());

        if ($changed !== []) {
            $lines[] = 'Changes:';
            foreach ($changed as $pkg) {
                $lines[] = $pkg->render();
            }
            $lines[] = '';
        }

        if ($unchanged !== []) {
            $lines[] = sprintf('Unchanged: %d package(s)', \count($unchanged));
            $lines[] = '';
        }

        if (!$this->hasChanges()) {
            $lines[] = 'No packages have releasable changes.';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tx_id' => $this->txId,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'global_bump' => $this->globalBump->value,
            'skew_detected' => $this->skewDetected,
            'packages' => array_map(
                static fn (PackageTagPlan $p) => $p->toArray(),
                $this->packages,
            ),
        ];
    }
}
