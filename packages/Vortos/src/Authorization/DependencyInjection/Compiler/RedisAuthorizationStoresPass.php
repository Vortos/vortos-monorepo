<?php

declare(strict_types=1);

namespace Vortos\Authorization\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Vortos\Authorization\Audit\AuthorizationAuditContextProvider;
use Vortos\Http\Contract\IpResolverInterface;
use Vortos\Authorization\Contract\AuthorizationCacheInvalidatorInterface;
use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;
use Vortos\Authorization\Contract\EmergencyDenyListInterface;
use Vortos\Authorization\Contract\RolePermissionStoreInterface;
use Vortos\Authorization\Resolver\CachedPermissionInvalidator;
use Vortos\Authorization\Resolver\CachedPermissionResolver;
use Vortos\Authorization\Resolver\DatabasePermissionResolver;
use Vortos\Authorization\Resolver\RequestMemoizedPermissionResolver;
use Vortos\Authorization\Resolver\RoleGenerationStore;
use Vortos\Authorization\Scope\Contract\ScopedPermissionStoreInterface;
use Vortos\Authorization\Scope\Storage\RedisScopedPermissionStore;
use Vortos\Authorization\Storage\DbalRolePermissionStore;
use Vortos\Authorization\Storage\GenerationalRolePermissionStore;
use Vortos\Authorization\Storage\RedisAuthorizationVersionStore;
use Vortos\Authorization\Storage\RedisEmergencyDenyList;
use Vortos\Authorization\Temporal\Contract\TemporalPermissionStoreInterface;
use Vortos\Authorization\Temporal\Storage\RedisTemporalPermissionStore;
use Vortos\Authorization\Tracing\AuthorizationTracer;

/**
 * Upgrades the authorization stores to their Redis-backed implementations when
 * \Redis is available, and injects RequestStack into the audit context provider.
 *
 * AuthorizationExtension::load() registers the Null/DBAL defaults unconditionally,
 * because a hasDefinition(\Redis::class) / hasDefinition(RequestStack::class) check
 * inside an Extension::load() runs against the isolated per-extension container
 * built by MergeExtensionConfigurationPass — where \Redis (registered by the Cache /
 * Auth extensions) and RequestStack (Http extension) are never visible regardless
 * of package load order. Without this pass the framework silently ran with no
 * distributed emergency deny-list, no authorization version store, no scoped /
 * temporal Redis stores, and no permission cache — a correctness and security gap.
 */
final class RedisAuthorizationStoresPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(RequestStack::class) || $container->hasAlias(RequestStack::class)) {
            if ($container->hasDefinition(AuthorizationAuditContextProvider::class)) {
                $container->getDefinition(AuthorizationAuditContextProvider::class)
                    ->setArgument('$requestStack', new Reference(RequestStack::class));
            }
        }

        if ($container->hasDefinition(IpResolverInterface::class) || $container->hasAlias(IpResolverInterface::class)) {
            if ($container->hasDefinition(AuthorizationAuditContextProvider::class)) {
                $container->getDefinition(AuthorizationAuditContextProvider::class)
                    ->setArgument('$ipResolver', new Reference(IpResolverInterface::class));
            }
        }

        if (!$container->hasDefinition(\Redis::class)) {
            return;
        }

        $this->wireRedisStores($container);
        $this->wireGenerationalRoleStore($container);
        $this->wireCachedResolver($container);
    }

    private function wireRedisStores(ContainerBuilder $container): void
    {
        $container->register(RedisEmergencyDenyList::class, RedisEmergencyDenyList::class)
            ->setArgument('$redis', new Reference(\Redis::class))
            ->setShared(true)->setPublic(false);
        $container->setAlias(EmergencyDenyListInterface::class, RedisEmergencyDenyList::class)->setPublic(false);

        $container->register(RedisAuthorizationVersionStore::class, RedisAuthorizationVersionStore::class)
            ->setArgument('$redis', new Reference(\Redis::class))
            ->setShared(true)->setPublic(false);
        $container->setAlias(AuthorizationVersionStoreInterface::class, RedisAuthorizationVersionStore::class)->setPublic(false);

        $container->register(RedisScopedPermissionStore::class, RedisScopedPermissionStore::class)
            ->setArgument('$redis', new Reference(\Redis::class))
            ->setShared(true)->setPublic(false);
        $container->setAlias(ScopedPermissionStoreInterface::class, RedisScopedPermissionStore::class)->setPublic(false);

        $container->register(RedisTemporalPermissionStore::class, RedisTemporalPermissionStore::class)
            ->setArgument('$redis', new Reference(\Redis::class))
            ->setShared(true)->setPublic(false);
        $container->setAlias(TemporalPermissionStoreInterface::class, RedisTemporalPermissionStore::class)->setPublic(false);
    }

    private function wireGenerationalRoleStore(ContainerBuilder $container): void
    {
        $container->register(RoleGenerationStore::class, RoleGenerationStore::class)
            ->setArgument('$redis', new Reference(\Redis::class))
            ->setShared(true)->setPublic(false);

        $container->register(GenerationalRolePermissionStore::class, GenerationalRolePermissionStore::class)
            ->setArgument('$inner', new Reference(DbalRolePermissionStore::class))
            ->setArgument('$generations', new Reference(RoleGenerationStore::class))
            ->setShared(true)->setPublic(false);
        $container->setAlias(RolePermissionStoreInterface::class, GenerationalRolePermissionStore::class)->setPublic(false);
    }

    private function wireCachedResolver(ContainerBuilder $container): void
    {
        $container->register(CachedPermissionResolver::class, CachedPermissionResolver::class)
            ->setArgument('$inner', new Reference(DatabasePermissionResolver::class))
            ->setArgument('$redis', new Reference(\Redis::class))
            ->setArgument('$generations', new Reference(RoleGenerationStore::class))
            ->setArgument('$tracer', new Reference(AuthorizationTracer::class))
            ->setShared(true)->setPublic(false);

        $container->register(CachedPermissionInvalidator::class, CachedPermissionInvalidator::class)
            ->setArgument('$resolver', new Reference(CachedPermissionResolver::class))
            ->setShared(true)->setPublic(false);
        $container->setAlias(AuthorizationCacheInvalidatorInterface::class, CachedPermissionInvalidator::class)->setPublic(false);

        // Memoized resolver now wraps the cached resolver instead of the bare DB one.
        $container->getDefinition(RequestMemoizedPermissionResolver::class)
            ->setArgument('$inner', new Reference(CachedPermissionResolver::class));
    }
}
