<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Authz\Management;

use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Exception\AccessDeniedException;
use Vortos\Http\Exception\ForbiddenException;
use Vortos\Http\Exception\UnauthorizedException;

final class PolicyEngineManagementAuthzGate implements ManagementAuthzGateInterface
{
    public function __construct(
        private readonly PolicyEngine $policy,
        private readonly CurrentUserProvider $currentUser,
    ) {}

    public function requirePermission(
        string $permission,
        ?string $projectId = null,
        ?string $environment = null,
    ): void {
        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) {
            throw new UnauthorizedException('Authentication required.');
        }

        try {
            $this->policy->authorize($identity, $permission);
        } catch (AccessDeniedException $e) {
            // Map unauthenticated reason to 401; all others to 403.
            $message = $e->getMessage();
            if (str_contains($message, 'Authentication required')) {
                throw new UnauthorizedException($message, $e);
            }
            throw new ForbiddenException($message, $e);
        }
    }

    public function can(string $permission, ?string $projectId = null, ?string $environment = null): bool
    {
        $identity = $this->currentUser->get();

        if (!$identity->isAuthenticated()) {
            return false;
        }

        try {
            $this->policy->authorize($identity, $permission);
            return true;
        } catch (AccessDeniedException) {
            return false;
        }
    }
}
