<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests\Tenant;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Vortos\PersistenceOrm\Tenant\TenantFilter;
use Vortos\PersistenceOrm\Tenant\TenantScopedEntityRegistry;

final class TenantFilterTest extends TestCase
{
    protected function tearDown(): void
    {
        TenantScopedEntityRegistry::load([]);
    }

    public function test_scoped_entity_gets_session_variable_predicate(): void
    {
        TenantScopedEntityRegistry::load([ScopedEntityFixture::class => 'tenant_id']);

        $sql = $this->filter()->addFilterConstraint($this->metadataFor(ScopedEntityFixture::class), 't0');

        $this->assertSame(
            "t0.tenant_id::text = current_setting('app.current_tenant', true)",
            $sql,
        );
    }

    public function test_custom_column(): void
    {
        TenantScopedEntityRegistry::load([CustomColumnEntityFixture::class => 'org_id']);

        $sql = $this->filter()->addFilterConstraint($this->metadataFor(CustomColumnEntityFixture::class), 'x');

        $this->assertStringContainsString('x.org_id::text = current_setting', $sql);
    }

    public function test_unscoped_entity_gets_no_constraint(): void
    {
        TenantScopedEntityRegistry::load([ScopedEntityFixture::class => 'tenant_id']);

        $sql = $this->filter()->addFilterConstraint($this->metadataFor(GlobalEntityFixture::class), 't0');

        $this->assertSame('', $sql);
    }

    public function test_predicate_is_tenant_invariant(): void
    {
        // The SQL must NOT embed a tenant value — it references the session var,
        // so the cached plan is shared across all tenants.
        TenantScopedEntityRegistry::load([ScopedEntityFixture::class => 'tenant_id']);

        $sql = $this->filter()->addFilterConstraint($this->metadataFor(ScopedEntityFixture::class), 't0');

        $this->assertStringNotContainsString('acme', $sql);
        $this->assertStringContainsString('current_setting', $sql);
    }

    private function filter(): TenantFilter
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('quote')->willReturnCallback(static fn(string $v) => "'{$v}'");

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);

        return new TenantFilter($em);
    }

    private function metadataFor(string $class): ClassMetadata
    {
        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('getName')->willReturn($class);

        return $meta;
    }
}
