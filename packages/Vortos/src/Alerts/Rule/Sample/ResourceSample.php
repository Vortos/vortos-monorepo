<?php

declare(strict_types=1);

namespace Vortos\Alerts\Rule\Sample;

/** Observed utilization for `resource_exhaustion`. */
final readonly class ResourceSample implements SampleInterface
{
    public function __construct(
        public float $usedPct,
        public string $resourceName,
    ) {}
}
