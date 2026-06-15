<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\PersistenceOrm\Attribute\UsesOrmEntity;
use Vortos\Tenant\TenantScopeResolver;

/**
 * Computes the tenant-scoped entity map at container-compile time.
 *
 * Scans every repository that declares #[UsesOrmEntity], reads its entity class,
 * and records those carrying #[TenantScoped] as entityClass => column. The map is
 * stored as the `vortos.tenant.orm_scoped_entities` parameter and handed to the
 * EntityManager factory, which loads it into {@see \Vortos\PersistenceOrm\Tenant\TenantScopedEntityRegistry}
 * at boot.
 *
 * Doing this here means the request path performs zero reflection to decide which
 * entities are tenant-scoped — it is a precomputed array lookup.
 *
 * Runs at TYPE_BEFORE_OPTIMIZATION (after OrmRepositoryCompilerPass), so all
 * repository definitions are present.
 */
final class TenantScopedEntitiesPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $map = [];

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $className = $definition->getClass() ?? $serviceId;

            if (!class_exists($className)) {
                continue;
            }

            $attributes = (new \ReflectionClass($className))->getAttributes(UsesOrmEntity::class);
            if ($attributes === []) {
                continue;
            }

            $entityClass = $attributes[0]->newInstance()->entityClass;
            $column = TenantScopeResolver::columnFor($entityClass);

            if ($column !== null) {
                $map[$entityClass] = $column;
            }
        }

        $container->setParameter('vortos.tenant.orm_scoped_entities', $map);
    }
}
