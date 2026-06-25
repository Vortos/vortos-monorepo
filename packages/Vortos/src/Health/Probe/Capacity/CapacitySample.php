<?php

declare(strict_types=1);

namespace Vortos\Health\Probe\Capacity;

use InvalidArgumentException;

/**
 * Neutral VO bridging a capacity probe's reading to a consumer. Health never depends
 * on Alerts; {@see \Vortos\Health\Integration\Alerts\CapacityAlertSource} (guarded,
 * Alerts-side) converts this into Alerts' own `ResourceSample`.
 */
final readonly class CapacitySample
{
    public function __construct(
        public string $resourceName,
        public float $usedPct,
    ) {
        if ($resourceName === '') {
            throw new InvalidArgumentException('CapacitySample resourceName must not be empty.');
        }
        if ($usedPct < 0.0 || $usedPct > 200.0) {
            throw new InvalidArgumentException('CapacitySample usedPct must be a sane percentage (0-200, allowing overload).');
        }
    }
}
