<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Authz;

use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;

/**
 * Default gate when no authorization engine is wired: never vetoes. `requiredScope` is
 * inert in an app that does not use the Authorization package.
 */
final class NullFlagAuthzGate implements FlagAuthzGateInterface
{
    public function allows(FeatureFlag $flag, FlagContext $context): bool
    {
        return true;
    }
}
