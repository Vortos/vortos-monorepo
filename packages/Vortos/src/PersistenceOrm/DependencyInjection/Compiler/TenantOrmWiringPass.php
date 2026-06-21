<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\DependencyInjection\Compiler;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\PersistenceOrm\Tenant\OrmTenantSessionBinder;
use Vortos\PersistenceOrm\Tenant\TenantFilter;
use Vortos\PersistenceOrm\Tenant\TenantStampListener;
use Vortos\PersistenceOrm\Transaction\OrmUnitOfWork;
use Vortos\Tenant\Session\TenantGucBinderInterface;
use Vortos\Tenant\TenantContext;

/**
 * Applies ORM tenant isolation wiring when the tenant package is installed.
 *
 * Lives in a compiler pass (PersistenceOrmPackage::build) rather than
 * PersistenceOrmExtension::load because a has(TenantContext::class) check inside
 * load() runs against the isolated per-extension merge container, where
 * TenantContext (owned by vortos-tenant) is never present. The extension
 * registers the EntityManager and OrmUnitOfWork with tenant-neutral defaults;
 * this pass patches the Doctrine filter, the prePersist stamp listener, the
 * scoped-entity map and the GUC binder onto them once the merged container is
 * known to contain TenantContext.
 */
final class TenantOrmWiringPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(TenantContext::class)
            || !$container->hasDefinition(EntityManager::class)) {
            return;
        }

        // Compile-time scoped-entity map; populated by TenantScopedEntitiesPass.
        if (!$container->hasParameter('vortos.tenant.orm_scoped_entities')) {
            $container->setParameter('vortos.tenant.orm_scoped_entities', []);
        }

        $container->register(TenantStampListener::class, TenantStampListener::class)
            ->setArgument('$tenantContext', new Reference(TenantContext::class))
            ->setShared(true)->setPublic(false);

        $container->getDefinition(EntityManager::class)
            ->setArgument('$filters', [TenantFilter::NAME => TenantFilter::class])
            ->setArgument('$enabledFilters', [TenantFilter::NAME])
            ->setArgument('$eventListeners', [[['prePersist'], new Reference(TenantStampListener::class)]])
            ->setArgument('$scopedEntities', '%vortos.tenant.orm_scoped_entities%');

        // Tenant GUC binder — sets app.current_tenant for the ORM filter + RLS.
        $container->register(OrmTenantSessionBinder::class, OrmTenantSessionBinder::class)
            ->setArgument('$em', new Reference(EntityManagerInterface::class))
            ->setArgument('$tenantContext', new Reference(TenantContext::class))
            ->setShared(true)->setPublic(false);

        // Unconditional (as in the original load()): the ORM binder is the
        // canonical TenantGucBinderInterface; the DBAL pass yields to it via its
        // own hasAlias/hasDefinition guard.
        $container->setAlias(TenantGucBinderInterface::class, OrmTenantSessionBinder::class)
            ->setPublic(true);

        $container->getDefinition(OrmUnitOfWork::class)
            ->setArgument('$tenantBinder', new Reference(OrmTenantSessionBinder::class));
    }
}
