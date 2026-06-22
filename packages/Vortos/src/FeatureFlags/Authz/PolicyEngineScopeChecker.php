<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Authz;

use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Exception\AccessDeniedException;
use Vortos\FeatureFlags\FlagContext;

/**
 * Real {@see FlagScopeCheckerInterface} adapter over the scope-aware {@see PolicyEngine}.
 *
 * The subject is the authenticated identity from {@see CurrentUserProvider} — taken from
 * the trusted server-side session, never from the attacker-controlled flag-context header.
 * An unauthenticated subject is never granted. A normal authorization denial returns false;
 * the gate ({@see ScopeFlagAuthzGate}) treats any other error as a fail-closed deny.
 */
final class PolicyEngineScopeChecker implements FlagScopeCheckerInterface
{
    public function __construct(
        private readonly PolicyEngine $policy,
        private readonly CurrentUserProvider $currentUser,
    ) {}

    public function isGranted(string $scope, FlagContext $context): bool
    {
        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) {
            return false;
        }

        try {
            $this->policy->authorize($identity, $scope);

            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
}
