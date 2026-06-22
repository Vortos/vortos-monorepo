<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Authz;

use Vortos\FeatureFlags\FlagContext;

/**
 * Port over the authorization engine: "does the current subject hold $scope?".
 * Implemented by {@see PolicyEngineScopeChecker}; faked in tests so the gate logic can be
 * verified without the full Auth stack.
 */
interface FlagScopeCheckerInterface
{
    public function isGranted(string $scope, FlagContext $context): bool;
}
