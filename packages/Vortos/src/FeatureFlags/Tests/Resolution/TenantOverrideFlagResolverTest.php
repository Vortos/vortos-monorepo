<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Tests\Resolution;

use PHPUnit\Framework\TestCase;
use Vortos\FeatureFlags\FeatureFlag;
use Vortos\FeatureFlags\FlagContext;
use Vortos\FeatureFlags\Resolution\GlobalFlagResolver;
use Vortos\FeatureFlags\Resolution\TenantOverrideFlagResolver;
use Vortos\FeatureFlags\Storage\FlagStorageInterface;
use Vortos\FeatureFlags\Storage\TenantFlagOverrideStorageInterface;
use Vortos\Tenant\TenantContext;

final class TenantOverrideFlagResolverTest extends TestCase
{
    public function test_no_tenant_set_returns_global(): void
    {
        $resolver = $this->resolver(new TenantContext());

        $flag = $resolver->resolve('x', new FlagContext('u1'));

        $this->assertNotNull($flag);
        $this->assertTrue($flag->enabled, 'global x is enabled');
    }

    public function test_tenant_override_beats_global(): void
    {
        $tenant = new TenantContext();
        $tenant->set('tenant-a');

        $flag = $this->resolver($tenant)->resolve('x', new FlagContext('u1'));

        $this->assertNotNull($flag);
        $this->assertFalse($flag->enabled, 'tenant-a overrides x to disabled');
    }

    public function test_other_tenant_does_not_see_the_override(): void
    {
        $tenant = new TenantContext();
        $tenant->set('tenant-b'); // has no overrides

        $flag = $this->resolver($tenant)->resolve('x', new FlagContext('u1'));

        $this->assertTrue($flag->enabled, 'tenant-b falls through to global');
    }

    public function test_system_scope_sees_global_only(): void
    {
        $tenant = new TenantContext();
        $tenant->runAsSystem(function () use ($tenant): void {
            $flag = $this->resolver($tenant)->resolve('x', new FlagContext('u1'));
            $this->assertTrue($flag->enabled, 'system scope never inherits a tenant override');
        });
    }

    public function test_context_header_tenant_id_cannot_drive_override(): void
    {
        // No tenant in TenantContext, but the (attacker-controllable) flag context claims one.
        $tenant  = new TenantContext();
        $context = new FlagContext('u1', trusted: ['tenantId' => 'tenant-a']);

        $flag = $this->resolver($tenant)->resolve('x', $context);

        $this->assertTrue(
            $flag->enabled,
            'override resolution must key off TenantContext, never the flag-context header',
        );
    }

    public function test_resolve_all_merges_overrides_and_tenant_only_flags(): void
    {
        $tenant = new TenantContext();
        $tenant->set('tenant-a');

        $all = $this->resolver($tenant)->resolveAll(new FlagContext('u1'));
        $byName = [];
        foreach ($all as $f) {
            $byName[$f->name] = $f;
        }

        $this->assertArrayHasKey('x', $byName);
        $this->assertFalse($byName['x']->enabled, 'x is the tenant override');
        $this->assertArrayHasKey('tenant-only', $byName, 'tenant-only override flag appears');
    }

    private function resolver(TenantContext $tenant): TenantOverrideFlagResolver
    {
        $global  = $this->globalFlag('x', enabled: true);
        $storage = $this->createMock(FlagStorageInterface::class);
        $storage->method('findByName')->willReturnCallback(
            fn(string $n) => $n === 'x' ? $global : null,
        );
        $storage->method('findAll')->willReturn([$global]);

        $overrides = new class implements TenantFlagOverrideStorageInterface {
            public function findAllForTenant(string $tenantId): array
            {
                if ($tenantId !== 'tenant-a') {
                    return [];
                }
                $now = new \DateTimeImmutable();

                return [
                    'x'           => (new FeatureFlag('id-x', 'x', '', false, [], null, $now, $now))->toArray(),
                    'tenant-only' => (new FeatureFlag('id-to', 'tenant-only', '', true, [], null, $now, $now))->toArray(),
                ];
            }
        };

        return new TenantOverrideFlagResolver(new GlobalFlagResolver($storage), $overrides, $tenant);
    }

    private function globalFlag(string $name, bool $enabled): FeatureFlag
    {
        $now = new \DateTimeImmutable();

        return new FeatureFlag('id-' . $name, $name, '', $enabled, [], null, $now, $now);
    }
}
