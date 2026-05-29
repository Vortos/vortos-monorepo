<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\DependencyInjection\Compiler;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\PersistenceOrm\Attribute\UsesOrmEntity;
use Vortos\PersistenceOrm\Write\OrmStore;

/**
 * Auto-wires OrmStore into repositories that declare #[UsesOrmEntity].
 *
 * For each service definition whose class carries #[UsesOrmEntity]:
 *   1. Registers a named OrmStore service: vortos.orm_store.<RepositoryClass>
 *      — wired with the shared EntityManagerInterface and the entity class string
 *   2. Sets the store as the $store constructor argument of the repository
 *
 * The EntityManager is encapsulated inside OrmStore — user repositories cannot
 * access persist(), flush(), clear(), or any other EM method directly.
 *
 * Runs at TYPE_BEFORE_OPTIMIZATION priority 8.
 */
final class OrmRepositoryCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(EntityManagerInterface::class) && !$container->hasDefinition(EntityManagerInterface::class)) {
            return;
        }

        foreach ($container->getDefinitions() as $serviceId => $definition) {
            $className = $definition->getClass() ?? $serviceId;

            if (!class_exists($className)) {
                continue;
            }

            $reflClass = new \ReflectionClass($className);
            $attrs     = $reflClass->getAttributes(UsesOrmEntity::class);

            if (empty($attrs)) {
                continue;
            }

            /** @var UsesOrmEntity $attr */
            $attr        = $attrs[0]->newInstance();
            $entityClass = $attr->entityClass;

            $storeId = 'vortos.orm_store.' . $className;
            $container->setDefinition($storeId, (new Definition(OrmStore::class))
                ->setArgument('$em', new Reference(EntityManagerInterface::class))
                ->setArgument('$entityClass', $entityClass)
                ->setShared(true)
                ->setPublic(false));

            $definition->setArgument('$store', new Reference($storeId));
        }
    }
}
