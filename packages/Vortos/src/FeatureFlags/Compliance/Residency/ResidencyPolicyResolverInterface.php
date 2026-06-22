<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Compliance\Residency;

interface ResidencyPolicyResolverInterface
{
    /**
     * Resolve the residency policy for a tenant.
     * Returns a policy with region='any' (no constraint) for unconfigured tenants.
     */
    public function resolveForTenant(string $tenantId): ResidencyPolicy;
}
