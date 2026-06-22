<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Compliance\Residency;

/**
 * Enforces data-residency requirements at the storage-routing seam.
 *
 * Security guarantee: **fails closed**. An unknown, missing, or misconfigured policy
 * throws `ResidencyViolationException` rather than allowing the operation. The caller
 * must catch and convert this to an appropriate HTTP/command error.
 *
 * Architecture:
 *  - Reads the effective policy for the tenant from the trusted `ResidencyPolicyResolver`.
 *  - Asserts that the proposed operation (flagWrite / auditExport) targets a datastore
 *    that satisfies the tenant's residency region.
 *  - Never trusts a header for region selection — only `ResidencyPolicy` (from the
 *    trusted TenantContext) is authoritative.
 */
final class ResidencyGuard
{
    /** @param array<string, string[]> $datastoreRegions maps datastoreKey → list of satisfied regions */
    public function __construct(
        private readonly ResidencyPolicyResolverInterface $resolver,
        private readonly array $datastoreRegions,
        private readonly string $defaultDatastoreKey = '',
    ) {}

    /**
     * Assert that the operation for $tenantId is permitted on $datastoreKey.
     *
     * @throws ResidencyViolationException if the region requirement is not met
     */
    public function assertPermitted(string $tenantId, string $datastoreKey = ''): void
    {
        $policy = $this->resolver->resolveForTenant($tenantId);

        if (!$policy->isConstrained()) {
            return; // No residency constraint — any datastore is fine
        }

        $key              = $datastoreKey !== '' ? $datastoreKey : $this->defaultDatastoreKey;
        $satisfiedRegions = $this->datastoreRegions[$key] ?? [];

        if (!in_array($policy->region, $satisfiedRegions, true)) {
            throw new ResidencyViolationException(sprintf(
                'Tenant %s requires region "%s" but datastore "%s" only satisfies [%s]',
                $tenantId,
                $policy->region,
                $key,
                implode(', ', $satisfiedRegions) ?: 'none',
            ));
        }
    }

    /**
     * Return the effective datastore key for the tenant, or null if no constraint.
     * Callers use this to route storage lookups to the correct regional store.
     */
    public function effectiveDatastoreKey(string $tenantId): ?string
    {
        $policy = $this->resolver->resolveForTenant($tenantId);

        if (!$policy->isConstrained()) {
            return null;
        }

        return $policy->datastoreKey !== '' ? $policy->datastoreKey : $this->defaultDatastoreKey;
    }
}
