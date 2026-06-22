<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Layer;

/**
 * One experiment's slice within a layer's bucket space.
 *
 * `sliceStart` and `weight` are in the 0–10000 bucket space (0.01% granularity,
 * consistent with Bucketing::BUCKETS). Slice occupies [sliceStart, sliceStart + weight).
 */
final class LayerMember
{
    public function __construct(
        /** Flag name of the experiment occupying this slice. */
        public readonly string $flagName,
        /** First bucket (inclusive) in this member's slice. Range: 0–9999. */
        public readonly int $sliceStart,
        /**
         * Slice width in bucket units. `sliceStart + weight` must be ≤ 10000.
         * Equivalent to `percentage * 100` (e.g. 20% → 2000 bucket units).
         */
        public readonly int $weight,
    ) {
        if ($this->sliceStart < 0 || $this->weight < 0 || ($this->sliceStart + $this->weight) > 10_000) {
            throw new \InvalidArgumentException(
                "LayerMember '{$this->flagName}': sliceStart({$this->sliceStart}) + weight({$this->weight}) must be ≤ 10000",
            );
        }
    }

    public function contains(int $bucket): bool
    {
        return $bucket >= $this->sliceStart && $bucket < ($this->sliceStart + $this->weight);
    }

    /** Weight expressed as a percentage (0–100). */
    public function weightPercent(): float
    {
        return $this->weight / 100.0;
    }
}
