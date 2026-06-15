<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests\Tenant;

use PHPUnit\Framework\TestCase;
use Vortos\PersistenceOrm\Tenant\TenantScopedEntityRegistry;

final class TenantScopedEntityRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        TenantScopedEntityRegistry::load([]);
    }

    public function test_load_and_lookup(): void
    {
        TenantScopedEntityRegistry::load([ScopedEntityFixture::class => 'tenant_id']);

        $this->assertSame('tenant_id', TenantScopedEntityRegistry::columnFor(ScopedEntityFixture::class));
        $this->assertTrue(TenantScopedEntityRegistry::isScoped(ScopedEntityFixture::class));
    }

    public function test_unknown_entity_is_not_scoped(): void
    {
        TenantScopedEntityRegistry::load([ScopedEntityFixture::class => 'tenant_id']);

        $this->assertNull(TenantScopedEntityRegistry::columnFor(GlobalEntityFixture::class));
        $this->assertFalse(TenantScopedEntityRegistry::isScoped(GlobalEntityFixture::class));
    }

    public function test_load_replaces_previous_map(): void
    {
        TenantScopedEntityRegistry::load([ScopedEntityFixture::class => 'tenant_id']);
        TenantScopedEntityRegistry::load([CustomColumnEntityFixture::class => 'org_id']);

        $this->assertNull(TenantScopedEntityRegistry::columnFor(ScopedEntityFixture::class));
        $this->assertSame('org_id', TenantScopedEntityRegistry::columnFor(CustomColumnEntityFixture::class));
    }
}
