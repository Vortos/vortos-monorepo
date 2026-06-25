<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule\Condition;

use Vortos\Observability\Slo\BurnRatePolicy;

/**
 * `slo_burn` — wraps the Block 16 multi-burn-rate policy directly so the fast/slow
 * page-worthy logic is never re-derived; single source of truth with Observability.
 */
final readonly class SloBurnCondition implements AlertConditionInterface
{
    public function __construct(
        public BurnRatePolicy $policy,
    ) {}

    public function fires(float $fastBurnRate, float $slowBurnRate): bool
    {
        return $this->policy->isPageWorthy($fastBurnRate, $slowBurnRate);
    }
}
