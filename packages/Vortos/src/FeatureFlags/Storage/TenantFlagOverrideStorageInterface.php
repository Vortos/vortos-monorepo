<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Storage;

/**
 * Storage for per-tenant flag overrides (Block 9). Each override is a full
 * {@see \Vortos\FeatureFlags\FeatureFlag::toArray()} snapshot scoped to one tenant; it
 * shadows the global flag of the same name for that tenant only.
 */
interface TenantFlagOverrideStorageInterface
{
    /**
     * All overrides for one tenant, keyed by flag name. Bulk-loaded once per request and
     * memoized by the resolver — a tenant typically overrides only a handful of flags.
     *
     * @return array<string,array<string,mixed>> flagName => FeatureFlag::toArray()
     */
    public function findAllForTenant(string $tenantId): array;
}
