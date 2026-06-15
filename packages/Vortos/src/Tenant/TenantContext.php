<?php

declare(strict_types=1);

namespace Vortos\Tenant;

use Symfony\Contracts\Service\ResetInterface;
use Vortos\Tenant\Exception\MissingTenantContextException;

/**
 * The ambient tenant for the current request / unit of work — Layer 1 of tenant
 * isolation.
 *
 * One shared instance per process. It implements {@see ResetInterface}, so the
 * framework clears it at the end of every request (critical under FrankenPHP /
 * Kafka worker mode, where the container is reused across requests).
 *
 * ## How it gets populated
 *
 *   - HTTP: TenantContextMiddleware sets it from the authenticated identity's
 *     tenant claim, right after authentication.
 *   - Workers / consumers: call {@see self::runAs()} with the tenant carried on
 *     the message before handling it.
 *   - Migrations / cross-tenant admin: {@see self::runAsSystem()} to bypass
 *     scoping deliberately and auditably.
 *
 * ## How it is consumed
 *
 * The persistence layer asks {@see self::scopingDecision()}:
 *   - a tenant id  → filter reads / stamp writes with it
 *   - SYSTEM       → no filter (see everything) — admin / migration
 *   - null         → fail closed (MissingTenantContextException)
 */
final class TenantContext implements ResetInterface
{
    public const SYSTEM = '__system__';

    private ?string $tenantId = null;
    private bool $system = false;

    public function tenantId(): ?string
    {
        return $this->tenantId;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function isSystem(): bool
    {
        return $this->system;
    }

    /**
     * The tenant id, or throw if none is set. Use where a tenant is mandatory
     * (e.g. stamping a new row).
     *
     * @throws MissingTenantContextException
     */
    public function requireTenantId(): string
    {
        if ($this->tenantId === null) {
            throw MissingTenantContextException::forScopedAccess('the current operation');
        }

        return $this->tenantId;
    }

    public function set(string $tenantId): void
    {
        if ($tenantId === '') {
            throw new \InvalidArgumentException('Tenant id cannot be empty.');
        }

        $this->tenantId = $tenantId;
        $this->system = false;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->system = false;
    }

    /**
     * Run $fn scoped to a specific tenant, restoring the previous context after
     * (even on exception). Use in consumers/jobs that act on one tenant's behalf.
     */
    public function runAs(string $tenantId, callable $fn): mixed
    {
        $prevId = $this->tenantId;
        $prevSystem = $this->system;
        $this->set($tenantId);

        try {
            return $fn();
        } finally {
            $this->tenantId = $prevId;
            $this->system = $prevSystem;
        }
    }

    /**
     * Run $fn in system scope — tenant filters are bypassed. For migrations and
     * deliberate cross-tenant administrative work only. Restores context after.
     */
    public function runAsSystem(callable $fn): mixed
    {
        $prevId = $this->tenantId;
        $prevSystem = $this->system;
        $this->tenantId = null;
        $this->system = true;

        try {
            return $fn();
        } finally {
            $this->tenantId = $prevId;
            $this->system = $prevSystem;
        }
    }

    /**
     * What the persistence layer should do right now:
     *   - a string tenant id → scope to it
     *   - self::SYSTEM       → no scoping (cross-tenant)
     *   - null               → caller must fail closed
     */
    public function scopingDecision(): ?string
    {
        if ($this->system) {
            return self::SYSTEM;
        }

        return $this->tenantId;
    }

    public function reset(): void
    {
        $this->clear();
    }
}
