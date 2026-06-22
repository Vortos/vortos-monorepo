<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Compliance;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\Compliance\Residency\ResidencyGuard;
use Vortos\FeatureFlags\Compliance\Residency\ResidencyPolicy;
use Vortos\FeatureFlags\Compliance\Residency\ResidencyPolicyResolverInterface;
use Vortos\FeatureFlags\Compliance\Residency\ResidencyViolationException;

final class ResidencyGuardTest extends TestCase
{
    private function makeGuard(
        ResidencyPolicy $policy,
        array $datastoreRegions = [],
        string $defaultKey = 'primary',
    ): ResidencyGuard {
        $resolver = new class($policy) implements ResidencyPolicyResolverInterface {
            public function __construct(private readonly ResidencyPolicy $p) {}
            public function resolveForTenant(string $tenantId): ResidencyPolicy { return $this->p; }
        };

        return new ResidencyGuard($resolver, $datastoreRegions, $defaultKey);
    }

    public function test_unconstrained_tenant_always_passes(): void
    {
        $policy = new ResidencyPolicy('tenant-1', ResidencyPolicy::REGION_ANY);
        $guard  = $this->makeGuard($policy);

        $guard->assertPermitted('tenant-1', 'any-datastore'); // Must not throw
        $this->assertTrue(true);
    }

    public function test_constrained_tenant_matching_region_passes(): void
    {
        $policy = new ResidencyPolicy('eu-tenant', ResidencyPolicy::REGION_EU);
        $guard  = $this->makeGuard($policy, ['eu-db' => ['eu']]);

        $guard->assertPermitted('eu-tenant', 'eu-db'); // Must not throw
        $this->assertTrue(true);
    }

    public function test_constrained_tenant_wrong_region_throws(): void
    {
        $policy = new ResidencyPolicy('eu-tenant', ResidencyPolicy::REGION_EU);
        $guard  = $this->makeGuard($policy, ['us-db' => ['us']]);

        $this->expectException(ResidencyViolationException::class);
        $this->expectExceptionMessage('eu');
        $guard->assertPermitted('eu-tenant', 'us-db');
    }

    public function test_fails_closed_when_datastore_not_configured(): void
    {
        $policy = new ResidencyPolicy('eu-tenant', ResidencyPolicy::REGION_EU);
        $guard  = $this->makeGuard($policy, []); // empty datastore map

        $this->expectException(ResidencyViolationException::class);
        $guard->assertPermitted('eu-tenant', 'unknown-db');
    }

    public function test_effective_datastore_key_returns_null_for_unconstrained(): void
    {
        $policy = new ResidencyPolicy('t1', ResidencyPolicy::REGION_ANY);
        $guard  = $this->makeGuard($policy);

        $this->assertNull($guard->effectiveDatastoreKey('t1'));
    }

    public function test_effective_datastore_key_returns_policy_key_for_constrained(): void
    {
        $policy = new ResidencyPolicy('t1', ResidencyPolicy::REGION_EU, 'eu-main');
        $guard  = $this->makeGuard($policy, ['eu-main' => ['eu']]);

        $this->assertSame('eu-main', $guard->effectiveDatastoreKey('t1'));
    }

    public function test_residency_policy_rejects_unknown_region(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ResidencyPolicy('t1', 'unknown-region');
    }

    public function test_all_known_regions_are_accepted(): void
    {
        foreach ([ResidencyPolicy::REGION_EU, ResidencyPolicy::REGION_US, ResidencyPolicy::REGION_APAC, ResidencyPolicy::REGION_ANY] as $region) {
            $policy = new ResidencyPolicy('t', $region);
            $this->assertSame($region, $policy->region);
        }
    }

    public function test_is_constrained_returns_false_for_any_region(): void
    {
        $policy = new ResidencyPolicy('t', ResidencyPolicy::REGION_ANY);
        $this->assertFalse($policy->isConstrained());
    }

    public function test_is_constrained_returns_true_for_specific_regions(): void
    {
        foreach ([ResidencyPolicy::REGION_EU, ResidencyPolicy::REGION_US, ResidencyPolicy::REGION_APAC] as $region) {
            $policy = new ResidencyPolicy('t', $region);
            $this->assertTrue($policy->isConstrained());
        }
    }
}
