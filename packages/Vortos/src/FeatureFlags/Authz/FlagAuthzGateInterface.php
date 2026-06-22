<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Authz;

use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;

/**
 * Decides whether a flag's authorization-scope requirement is satisfied for the current
 * subject (Block 9).
 *
 * **Deny-only invariant:** this gate can only turn a flag OFF (when the subject lacks the
 * required scope). It can never turn a flag ON — the flag engine remains the single source
 * of "should this be on", with authz as a veto. A flag without a `requiredScope` always
 * passes.
 */
interface FlagAuthzGateInterface
{
    public function allows(FeatureFlag $flag, FlagContext $context): bool;
}
