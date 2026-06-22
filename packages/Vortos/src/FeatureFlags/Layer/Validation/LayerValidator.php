<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Layer\Validation;

use Vortos\FeatureFlags\Exception\InvalidFlagException;
use Vortos\FeatureFlags\Layer\Layer;
use Vortos\FeatureFlags\Layer\LayerMember;
use Vortos\FeatureFlags\Layer\LayerStorageInterface;
use Vortos\FeatureFlags\Targeting\Bucketing;

/**
 * Validates a Layer at write time (PLATFORM §17 #11).
 *
 * Enforces:
 *  1. holdoutWeight + Σ member.weight ≤ Bucketing::BUCKETS (no over-allocation)
 *  2. No flag appears in more than one layer
 *  3. Member slices are contiguous (no overlap, no gap between members) — we auto-assign
 *     sliceStart, so this validates that the auto-assigned layout is consistent
 *  4. Layer name and salt are non-empty
 */
final class LayerValidator
{
    public function __construct(
        private readonly LayerStorageInterface $storage,
    ) {}

    /** @throws InvalidFlagException */
    public function validate(Layer $layer): void
    {
        $this->validateBasics($layer);
        $this->validateAllocation($layer);
        $this->validateNoMultiLayerMembership($layer);
    }

    private function validateBasics(Layer $layer): void
    {
        if ($layer->name === '') {
            throw new InvalidFlagException('Layer name must not be empty');
        }
        if ($layer->salt === '') {
            throw new InvalidFlagException('Layer salt must not be empty');
        }
    }

    private function validateAllocation(Layer $layer): void
    {
        $total = $layer->totalAllocated();

        if ($total > Bucketing::BUCKETS) {
            throw new InvalidFlagException(sprintf(
                'Layer "%s" over-allocated: holdout(%d) + members(%d) = %d > %d',
                $layer->name,
                $layer->holdoutWeight,
                $total - $layer->holdoutWeight,
                $total,
                Bucketing::BUCKETS,
            ));
        }

        // Validate no member slice overlaps another
        $seen = [];
        foreach ($layer->members as $member) {
            for ($b = $member->sliceStart; $b < $member->sliceStart + $member->weight; $b++) {
                if (isset($seen[$b])) {
                    throw new InvalidFlagException(sprintf(
                        'Layer "%s": bucket %d is claimed by both "%s" and "%s"',
                        $layer->name,
                        $b,
                        $seen[$b],
                        $member->flagName,
                    ));
                }
                $seen[$b] = $member->flagName;
            }
        }
    }

    private function validateNoMultiLayerMembership(Layer $layer): void
    {
        foreach ($layer->members as $member) {
            $existingLayer = $this->storage->findByFlagName($member->flagName);
            if ($existingLayer !== null && $existingLayer->id !== $layer->id) {
                throw new InvalidFlagException(sprintf(
                    'Flag "%s" already belongs to layer "%s" — a flag may belong to at most one layer',
                    $member->flagName,
                    $existingLayer->name,
                ));
            }
        }
    }

    /**
     * Build a layer with auto-assigned contiguous slices (convenience factory).
     * Members are laid out immediately after the holdout range.
     *
     * @param array<string, int> $memberWeights flagName → weight (in bucket units, i.e. percentage * 100)
     * @throws InvalidFlagException on over-allocation
     */
    public static function buildLayer(
        string $id,
        string $name,
        string $salt,
        int $holdoutWeight,
        array $memberWeights,
        string $projectId = 'default',
    ): Layer {
        // Pre-check total allocation before constructing members (LayerMember also validates
        // sliceStart+weight <= BUCKETS, so we must catch over-allocation here with a domain
        // exception rather than letting the generic constructor InvalidArgumentException bubble).
        $totalWeight = array_sum($memberWeights);
        $total       = $holdoutWeight + $totalWeight;

        if ($total > Bucketing::BUCKETS) {
            throw new InvalidFlagException(sprintf(
                'Layer "%s" over-allocated: holdout(%d) + members(%d) = %d > %d',
                $name,
                $holdoutWeight,
                (int) $totalWeight,
                $total,
                Bucketing::BUCKETS,
            ));
        }

        $members = [];
        $cursor  = $holdoutWeight;

        foreach ($memberWeights as $flagName => $weight) {
            $members[] = new LayerMember($flagName, $cursor, $weight);
            $cursor   += $weight;
        }

        return new Layer($id, $name, $salt, $holdoutWeight, $members, $projectId);
    }
}
