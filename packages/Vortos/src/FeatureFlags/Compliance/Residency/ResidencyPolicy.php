<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Compliance\Residency;

/**
 * Per-tenant data residency configuration.
 *
 * Immutable value object resolved exclusively from the trusted TenantContext — never
 * from a header or user input. Declares which region a tenant's data must remain in
 * and which named datastore key is authorised for that region.
 *
 * v1 scope: enforced at the storage-routing seam (DatabaseFlagStorage / audit repo
 * region selection hooks). Cross-region replication is explicitly not built here — it
 * is an operational concern outside this package.
 */
final class ResidencyPolicy
{
    public const REGION_EU   = 'eu';
    public const REGION_US   = 'us';
    public const REGION_APAC = 'apac';
    public const REGION_ANY  = 'any';

    public function __construct(
        /** Tenant this policy applies to. */
        public readonly string $tenantId,
        /**
         * Required storage region. 'any' means no residency constraint (default for
         * non-regulated tenants). One of: eu, us, apac, any.
         */
        public readonly string $region,
        /**
         * Named datastore key that satisfies the region requirement. The storage adapter
         * maps this to the actual connection. An empty string means the default store.
         */
        public readonly string $datastoreKey = '',
    ) {
        if (!in_array($this->region, [self::REGION_EU, self::REGION_US, self::REGION_APAC, self::REGION_ANY], true)) {
            throw new \InvalidArgumentException("Unknown residency region: {$this->region}");
        }
    }

    public function isConstrained(): bool
    {
        return $this->region !== self::REGION_ANY;
    }
}
