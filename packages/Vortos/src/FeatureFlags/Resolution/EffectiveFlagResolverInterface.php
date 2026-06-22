<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Resolution;

use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;

/**
 * Resolves the *effective* flag for a context — the override-aware step that runs before
 * evaluation (Block 9).
 *
 * Implementations form a chain: tenant override → (environment, Block 10) → global. The
 * interface is the seam that lets later dimensions slot in without touching the evaluator.
 */
interface EffectiveFlagResolverInterface
{
    public function resolve(string $name, FlagContext $context): ?FeatureFlag;

    /**
     * The effective set of all flags for this context (global, with overrides applied).
     *
     * @return FeatureFlag[]
     */
    public function resolveAll(FlagContext $context): array;
}
