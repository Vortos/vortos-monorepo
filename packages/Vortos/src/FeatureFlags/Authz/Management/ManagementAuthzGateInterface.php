<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Authz\Management;

interface ManagementAuthzGateInterface
{
    /**
     * Assert the current user holds $permission.
     * Throws ForbiddenException (403) or UnauthorizedException (401) on deny.
     */
    public function requirePermission(
        string $permission,
        ?string $projectId = null,
        ?string $environment = null,
    ): void;

    public function can(string $permission, ?string $projectId = null, ?string $environment = null): bool;
}
