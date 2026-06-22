<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Resolution;

use Symfony\Contracts\Service\ResetInterface;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\Storage\TenantFlagOverrideStorageInterface;
use Vortos\Tenant\TenantContext;

/**
 * Resolution-chain link that shadows global flags with per-tenant overrides (Block 9).
 *
 * The active tenant comes from the trusted {@see TenantContext}, never from client input —
 * so a spoofed `X-Vortos-Flag-Context.tenantId` can neither read nor apply another tenant's
 * override. Behaviour by scope:
 *
 *  - no tenant set / `TenantContext` absent → pure passthrough to global (the common,
 *    zero-extra-query path),
 *  - SYSTEM scope → global only (an override is never silently inherited cross-tenant),
 *  - a tenant set → the tenant's overrides shadow global by flag name.
 *
 * Overrides are bulk-loaded once per request and memoized (a tenant overrides few flags),
 * mirroring the segment registry. Reset between requests for worker mode.
 */
final class TenantOverrideFlagResolver implements EffectiveFlagResolverInterface, ResetInterface
{
    /** @var array<string,array<string,mixed>>|null memoized overrides for $memoTenant */
    private ?array $memo = null;
    private ?string $memoTenant = null;

    public function __construct(
        private readonly EffectiveFlagResolverInterface $inner,
        private readonly TenantFlagOverrideStorageInterface $overrides,
        private readonly ?TenantContext $tenantContext = null,
    ) {}

    public function resolve(string $name, FlagContext $context): ?FeatureFlag
    {
        $tenantId = $this->activeTenant();
        if ($tenantId === null) {
            return $this->inner->resolve($name, $context);
        }

        $map = $this->overridesFor($tenantId);

        return isset($map[$name])
            ? FeatureFlag::fromArray($map[$name])
            : $this->inner->resolve($name, $context);
    }

    public function resolveAll(FlagContext $context): array
    {
        $global   = $this->inner->resolveAll($context);
        $tenantId = $this->activeTenant();
        if ($tenantId === null) {
            return $global;
        }

        $map = $this->overridesFor($tenantId);
        if ($map === []) {
            return $global;
        }

        $byName = [];
        foreach ($global as $flag) {
            $byName[$flag->name] = $flag;
        }
        foreach ($map as $name => $data) {
            $byName[$name] = FeatureFlag::fromArray($data);
        }

        return array_values($byName);
    }

    private function activeTenant(): ?string
    {
        if ($this->tenantContext === null || $this->tenantContext->isSystem()) {
            return null;
        }

        return $this->tenantContext->tenantId();
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function overridesFor(string $tenantId): array
    {
        if ($this->memoTenant !== $tenantId) {
            $this->memo       = $this->overrides->findAllForTenant($tenantId);
            $this->memoTenant = $tenantId;
        }

        return $this->memo ?? [];
    }

    public function reset(): void
    {
        $this->memo       = null;
        $this->memoTenant = null;
    }
}
