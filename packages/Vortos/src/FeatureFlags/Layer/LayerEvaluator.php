<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Layer;

use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;

/**
 * Determines whether a context falls into a specific flag's slice within a layer.
 *
 * This is the mutual-exclusion gate: only the flag whose slice contains the context's
 * layer bucket may fire. All other member flags in the same layer are suppressed for
 * that context (they receive control, not their rollout value).
 *
 * Performance: one MurmurHash3 call per layered flag eval. Non-layered flags bypass
 * this entirely (zero cost). The result is deterministic and stateless.
 *
 * Safe-default: any error (missing layer, flag not a member, invalid context) returns
 * false so the flag stays off — never silently promotes a context into an experiment.
 */
final class LayerEvaluator
{
    public function __construct(
        private readonly LayerStorageInterface $storage,
    ) {}

    /**
     * Return true if and only if:
     *  1. The flag belongs to a layer.
     *  2. The context's bucket falls within this flag's slice (not holdout, not another member's slice).
     *
     * Safe-defaults to false on any missing/invalid config.
     */
    public function isInSlice(FeatureFlag $flag, FlagContext $context): bool
    {
        if ($flag->layerId === null) {
            return false;
        }

        $layer = $this->storage->findById($flag->layerId);
        if ($layer === null) {
            return false; // Missing layer config → safe-default: don't fire
        }

        $member = $layer->findMember($flag->name);
        if ($member === null) {
            return false; // Flag not in this layer → safe-default
        }

        $key = $context->bucketingValue($flag->bucketBy);
        if ($key === null) {
            return false; // Anonymous context without a bucketing key → don't fire
        }

        $layerBucket = $layer->bucketFor($key);

        // Holdout: context is always in control for all experiments in this layer
        if ($layer->isHoldout($layerBucket)) {
            return false;
        }

        // Mutual exclusion: this flag only fires if its slice wins for this bucket
        return $member->contains($layerBucket);
    }

    /**
     * Return the winning member for a context within a layer, or null for holdout/gap.
     * Used for debugging and admin UI (which experiment did this user fall into?).
     */
    public function winnerForContext(Layer $layer, FlagContext $context, string $bucketBy): ?LayerMember
    {
        $key = $context->bucketingValue($bucketBy);
        if ($key === null) {
            return null;
        }

        return $layer->winner($layer->bucketFor($key));
    }
}
