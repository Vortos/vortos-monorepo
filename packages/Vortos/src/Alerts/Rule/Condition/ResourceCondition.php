<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule\Condition;

use InvalidArgumentException;
use Vortos\Alerts\Severity;

/** `resource_exhaustion` — disk/CPU/RAM thresholds; default 85% warn / 95% critical. */
final readonly class ResourceCondition implements AlertConditionInterface
{
    public function __construct(
        public float $warnPct = 85.0,
        public float $criticalPct = 95.0,
    ) {
        if ($warnPct <= 0.0 || $warnPct > 100.0) {
            throw new InvalidArgumentException('ResourceCondition warnPct must be in (0, 100].');
        }
        if ($criticalPct <= 0.0 || $criticalPct > 100.0) {
            throw new InvalidArgumentException('ResourceCondition criticalPct must be in (0, 100].');
        }
        if ($warnPct >= $criticalPct) {
            throw new InvalidArgumentException('ResourceCondition warnPct must be < criticalPct.');
        }
    }

    public function severityFor(float $usedPct): ?Severity
    {
        if ($usedPct >= $this->criticalPct) {
            return Severity::Critical;
        }
        if ($usedPct >= $this->warnPct) {
            return Severity::Warning;
        }

        return null;
    }
}
