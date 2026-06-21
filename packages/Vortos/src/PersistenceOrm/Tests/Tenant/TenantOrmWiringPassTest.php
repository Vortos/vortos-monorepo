<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\Tests\Tenant;

use Doctrine\ORM\EntityManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\PersistenceOrm\DependencyInjection\Compiler\TenantOrmWiringPass;
use Vortos\PersistenceOrm\Tenant\OrmTenantSessionBinder;
use Vortos\PersistenceOrm\Tenant\TenantFilter;
use Vortos\PersistenceOrm\Tenant\TenantStampListener;
use Vortos\PersistenceOrm\Transaction\OrmUnitOfWork;
use Vortos\Tenant\Session\TenantGucBinderInterface;
use Vortos\Tenant\TenantContext;

final class TenantOrmWiringPassTest extends TestCase
{
    public function test_patches_entity_manager_and_unit_of_work_when_tenant_context_present(): void
    {
        $container = $this->containerWithEntityManager();
        $container->register(TenantContext::class, TenantContext::class);

        (new TenantOrmWiringPass())->process($container);

        $em = $container->getDefinition(EntityManager::class);
        $this->assertSame([TenantFilter::NAME => TenantFilter::class], $em->getArgument('$filters'));
        $this->assertSame([TenantFilter::NAME], $em->getArgument('$enabledFilters'));
        $this->assertSame('%vortos.tenant.orm_scoped_entities%', $em->getArgument('$scopedEntities'));

        $this->assertTrue($container->hasDefinition(TenantStampListener::class));
        $this->assertTrue($container->hasDefinition(OrmTenantSessionBinder::class));
        $this->assertSame(
            OrmTenantSessionBinder::class,
            (string) $container->getAlias(TenantGucBinderInterface::class),
        );
        $this->assertSame(
            OrmTenantSessionBinder::class,
            (string) $container->getDefinition(OrmUnitOfWork::class)->getArgument('$tenantBinder'),
        );
    }

    public function test_no_op_leaves_neutral_defaults_when_tenant_context_absent(): void
    {
        $container = $this->containerWithEntityManager();

        (new TenantOrmWiringPass())->process($container);

        $em = $container->getDefinition(EntityManager::class);
        $this->assertSame([], $em->getArgument('$filters'));
        $this->assertSame([], $em->getArgument('$scopedEntities'));
        $this->assertFalse($container->hasDefinition(TenantStampListener::class));
        $this->assertFalse($container->hasAlias(TenantGucBinderInterface::class));
    }

    private function containerWithEntityManager(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(EntityManager::class, EntityManager::class)
            ->setArgument('$filters', [])
            ->setArgument('$enabledFilters', [])
            ->setArgument('$eventListeners', [])
            ->setArgument('$scopedEntities', []);
        $container->register(OrmUnitOfWork::class, OrmUnitOfWork::class);

        return $container;
    }
}
