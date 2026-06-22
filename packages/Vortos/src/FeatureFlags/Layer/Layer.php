<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Layer;

use Vortos\FeatureFlags\Targeting\Bucketing;

/**
 * A mutual-exclusion layer — a partitioned user-space where each context falls
 * into at most one experiment (Statsig-style layers/universes).
 *
 * Bucket space: 0–9999 (Bucketing::BUCKETS). Layout:
 *
 *   [0 .. holdoutWeight)            → holdout: always returns control for all members
 *   [holdoutWeight .. holdoutWeight + members[0].weight) → experiment 0
 *   [prev_end .. prev_end + members[1].weight)            → experiment 1
 *   …
 *
 * The layer's own `salt` is used to compute the bucketing position (distinct from any
 * flag name, so layered and non-layered flags are independently salted and can coexist
 * in the same namespace without collision).
 *
 * Invariants enforced by LayerValidator at write time:
 *  - holdoutWeight + Σ member.weight ≤ Bucketing::BUCKETS
 *  - each flag appears in at most one layer
 *  - no slice overlaps (by construction: slices are contiguous)
 */
final class Layer
{
    /**
     * @param LayerMember[] $members Ordered, non-overlapping slices after the holdout range
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        /** Deterministic salt for layer-level bucketing (distinct from all flag names). */
        public readonly string $salt,
        /**
         * Bucket units [0, holdoutWeight) are reserved for holdout.
         * Contexts in holdout always receive control for every experiment in this layer.
         * Range: 0–10000.
         */
        public readonly int $holdoutWeight,
        public readonly array $members,
        public readonly string $projectId = 'default',
    ) {
        if ($this->holdoutWeight < 0 || $this->holdoutWeight > Bucketing::BUCKETS) {
            throw new \InvalidArgumentException('holdoutWeight must be 0–10000');
        }
    }

    /** Whether the context's bucket position falls in the holdout range. */
    public function isHoldout(int $layerBucket): bool
    {
        return $layerBucket < $this->holdoutWeight;
    }

    /** Return the member whose slice contains the bucket, or null (holdout / gap). */
    public function winner(int $layerBucket): ?LayerMember
    {
        if ($this->isHoldout($layerBucket)) {
            return null;
        }

        foreach ($this->members as $member) {
            if ($member->contains($layerBucket)) {
                return $member;
            }
        }

        return null; // In the gap (unallocated space)
    }

    /** Find the member for a specific flag name, or null if the flag is not in this layer. */
    public function findMember(string $flagName): ?LayerMember
    {
        foreach ($this->members as $member) {
            if ($member->flagName === $flagName) {
                return $member;
            }
        }

        return null;
    }

    /** Total allocated weight (holdout + all members), in bucket units. */
    public function totalAllocated(): int
    {
        return $this->holdoutWeight + array_sum(array_map(fn(LayerMember $m) => $m->weight, $this->members));
    }

    /** Bucket position for a context key, using the layer's dedicated salt. */
    public function bucketFor(string $contextKey): int
    {
        return Bucketing::bucket($this->salt, $contextKey);
    }
}
