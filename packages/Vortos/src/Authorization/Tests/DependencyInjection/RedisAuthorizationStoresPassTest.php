<?php

declare(strict_types=1);

namespace Vortos\Authorization\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpFoundation\RequestStack;
use Vortos\Authorization\Audit\AuthorizationAuditContextProvider;
use Vortos\Authorization\Contract\AuthorizationCacheInvalidatorInterface;
use Vortos\Authorization\Contract\EmergencyDenyListInterface;
use Vortos\Authorization\Contract\RolePermissionStoreInterface;
use Vortos\Authorization\DependencyInjection\Compiler\RedisAuthorizationStoresPass;
use Vortos\Authorization\Resolver\CachedPermissionInvalidator;
use Vortos\Authorization\Resolver\CachedPermissionResolver;
use Vortos\Authorization\Resolver\DatabasePermissionResolver;
use Vortos\Authorization\Resolver\RequestMemoizedPermissionResolver;
use Vortos\Authorization\Storage\GenerationalRolePermissionStore;
use Vortos\Authorization\Storage\RedisEmergencyDenyList;

final class RedisAuthorizationStoresPassTest extends TestCase
{
    public function test_leaves_defaults_when_redis_absent(): void
    {
        $container = $this->baseContainer();

        (new RedisAuthorizationStoresPass())->process($container);

        $this->assertFalse($container->hasDefinition(RedisEmergencyDenyList::class));
        $this->assertFalse($container->hasDefinition(CachedPermissionResolver::class));
        $this->assertSame(
            DatabasePermissionResolver::class,
            (string) $container->getDefinition(RequestMemoizedPermissionResolver::class)->getArgument('$inner'),
        );
    }

    public function test_upgrades_to_redis_stores_and_cached_resolver(): void
    {
        $container = $this->baseContainer();
        $container->setDefinition(\Redis::class, new Definition(\Redis::class));

        (new RedisAuthorizationStoresPass())->process($container);

        $this->assertSame(RedisEmergencyDenyList::class, (string) $container->getAlias(EmergencyDenyListInterface::class));
        $this->assertSame(GenerationalRolePermissionStore::class, (string) $container->getAlias(RolePermissionStoreInterface::class));
        $this->assertSame(CachedPermissionInvalidator::class, (string) $container->getAlias(AuthorizationCacheInvalidatorInterface::class));
        $this->assertTrue($container->hasDefinition(CachedPermissionResolver::class));
        $this->assertSame(
            CachedPermissionResolver::class,
            (string) $container->getDefinition(RequestMemoizedPermissionResolver::class)->getArgument('$inner'),
        );
    }

    public function test_injects_request_stack_when_present_regardless_of_redis(): void
    {
        $container = $this->baseContainer();
        $container->setDefinition(RequestStack::class, new Definition(RequestStack::class));

        (new RedisAuthorizationStoresPass())->process($container);

        $arg = $container->getDefinition(AuthorizationAuditContextProvider::class)->getArgument('$requestStack');
        $this->assertInstanceOf(Reference::class, $arg);
        $this->assertSame(RequestStack::class, (string) $arg);
    }

    public function test_request_stack_null_when_absent(): void
    {
        $container = $this->baseContainer();

        (new RedisAuthorizationStoresPass())->process($container);

        $this->assertNull($container->getDefinition(AuthorizationAuditContextProvider::class)->getArgument('$requestStack'));
    }

    private function baseContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->register(AuthorizationAuditContextProvider::class, AuthorizationAuditContextProvider::class)
            ->setArgument('$requestStack', null);
        $container->register(DatabasePermissionResolver::class, DatabasePermissionResolver::class);
        $container->register(RequestMemoizedPermissionResolver::class, RequestMemoizedPermissionResolver::class)
            ->setArgument('$inner', new Reference(DatabasePermissionResolver::class));
        return $container;
    }
}
