<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Authz\Management;

/**
 * No-op gate for applications without the Authorization package.
 * All permission checks pass unconditionally.
 */
final class NullManagementAuthzGate implements ManagementAuthzGateInterface
{
    public function requirePermission(
        string $permission,
        ?string $projectId = null,
        ?string $environment = null,
    ): void {
        // No-op: allow everything when Authorization is not wired.
    }

    public function can(string $permission, ?string $projectId = null, ?string $environment = null): bool
    {
        return true;
    }
}
