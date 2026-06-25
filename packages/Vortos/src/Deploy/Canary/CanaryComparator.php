<?php

declare(strict_types=1);

namespace Vortos\Deploy\Canary;

enum CanaryComparator
{
    /** Staged value compared directly against a fixed threshold (the SLO objective). */
    case AbsoluteThreshold;

    /**
     * Default for latency/error-rate: staged evaluated against stable × (1 + tolerance).
     * A region-wide spike moves both colors equally → never a false rollback.
     */
    case RelativeToBaseline;
}
