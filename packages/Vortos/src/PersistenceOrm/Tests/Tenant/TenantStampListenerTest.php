<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests\Tenant;

use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use PHPUnit\Framework\TestCase;
use Vortos\PersistenceOrm\Tenant\TenantScopedEntityRegistry;
use Vortos\PersistenceOrm\Tenant\TenantStampListener;
use Vortos\Tenant\Exception\MissingTenantContextException;
use Vortos\Tenant\TenantContext;

final class TenantStampListenerTest extends TestCase
{
    protected function tearDown(): void
    {
        TenantScopedEntityRegistry::load([]);
    }

    public function test_stamps_tenant_on_scoped_entity(): void
    {
        TenantScopedEntityRegistry::load([ScopedEntityFixture::class => 'tenant_id']);

        $context = new TenantContext();
        $context->set('acme');

        $entity = new ScopedEntityFixture();

        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('getFieldForColumn')->with('tenant_id')->willReturn('tenantId');
        $meta->expects($this->once())
            ->method('setFieldValue')
            ->with($entity, 'tenantId', 'acme');

        (new TenantStampListener($context))->prePersist($this->args($entity, $meta));
    }

    public function test_ignores_unscoped_entity(): void
    {
        TenantScopedEntityRegistry::load([ScopedEntityFixture::class => 'tenant_id']);

        $context = new TenantContext(); // no tenant — must NOT throw for a global entity
        $entity  = new GlobalEntityFixture();

        $meta = $this->createMock(ClassMetadata::class);
        $meta->expects($this->never())->method('setFieldValue');

        (new TenantStampListener($context))->prePersist($this->args($entity, $meta));
    }

    public function test_scoped_write_without_tenant_fails_closed(): void
    {
        TenantScopedEntityRegistry::load([ScopedEntityFixture::class => 'tenant_id']);

        $context = new TenantContext();
        $entity  = new ScopedEntityFixture();

        $meta = $this->createMock(ClassMetadata::class);
        $meta->method('getFieldForColumn')->willReturn('tenantId');

        $this->expectException(MissingTenantContextException::class);
        (new TenantStampListener($context))->prePersist($this->args($entity, $meta));
    }

    private function args(object $entity, ClassMetadata $meta): PrePersistEventArgs
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getClassMetadata')->with($entity::class)->willReturn($meta);

        return new PrePersistEventArgs($entity, $em);
    }
}
