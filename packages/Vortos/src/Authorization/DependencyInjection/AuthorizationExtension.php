<?php
declare(strict_types=1);

namespace Vortos\Authorization\DependencyInjection;

use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Authorization\Attribute\AsPolicy;
use Vortos\Authorization\Contract\PolicyRegistryInterface;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Engine\PolicyRegistry;
use Vortos\Authorization\Middleware\AuthorizationMiddleware;
use Vortos\Authorization\Ownership\Middleware\OwnershipMiddleware;
use Vortos\Authorization\Scope\Contract\ScopedPermissionStoreInterface;
use Vortos\Authorization\Scope\Contract\ScopeMode;
use Vortos\Authorization\Scope\ScopeResolverRegistry;
use Vortos\Authorization\Scope\ScopedAuthorizationManager;
use Vortos\Authorization\Scope\Storage\RedisScopedPermissionStore;
use Vortos\Authorization\Temporal\Storage\RedisTemporalPermissionStore;
use Vortos\Authorization\Temporal\TemporalAuthorizationManager;
use Vortos\Authorization\Http\PermissionsController;
use Vortos\Authorization\Voter\RoleVoter;

final class AuthorizationExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_authorization';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $projectDir = $container->getParameter('kernel.project_dir');
        $env = $container->getParameter('kernel.env');

        $config = new VortosAuthorizationConfig();

        $base = $projectDir . '/config/authorization.php';
        if (file_exists($base)) {
            (require $base)($config);
        }

        $envFile = $projectDir . '/config/' . $env . '/authorization.php';
        if (file_exists($envFile)) {
            (require $envFile)($config);
        }

        $resolved = $this->processConfiguration(new Configuration(), [$config->toArray()]);

        // RoleVoter
        $container->register(RoleVoter::class, RoleVoter::class)
            ->setArguments([$resolved['role_hierarchy']])
            ->setShared(true)->setPublic(true);

        // Permissions endpoint — returns hierarchy-expanded roles for the current user
        $container->register(PermissionsController::class, PermissionsController::class)
            ->setArgument('$currentUser', new Reference(\Vortos\Auth\Identity\CurrentUserProvider::class))
            ->setArgument('$roleVoter', new Reference(RoleVoter::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);

        // Policy ServiceLocator
        $container->register('vortos.authorization.policy_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->addTag('container.service_locator')
            ->setPublic(false);

        // PolicyRegistry + Engine
        $container->register(PolicyRegistry::class, PolicyRegistry::class)
            ->setArgument('$policies', new Reference('vortos.authorization.policy_locator'))
            ->setShared(true)->setPublic(false);
        $container->setAlias(PolicyRegistryInterface::class, PolicyRegistry::class)->setPublic(false);

        $container->register(PolicyEngine::class, PolicyEngine::class)
            ->setArgument('$registry', new Reference(PolicyRegistryInterface::class))
            ->setShared(true)->setPublic(true);

        // AuthorizationMiddleware
        $container->register(AuthorizationMiddleware::class, AuthorizationMiddleware::class)
            ->setArguments([new Reference(PolicyEngine::class), new Reference(\Vortos\Auth\Identity\CurrentUserProvider::class)])
            ->setShared(true)->setPublic(true)
            ->addTag('kernel.event_subscriber');

        // Ownership middleware
        $container->register(OwnershipMiddleware::class, OwnershipMiddleware::class)
            ->setArguments([
                new Reference(\Vortos\Auth\Identity\CurrentUserProvider::class),
                new Reference(PolicyEngine::class),
                [], // routeMap — filled by OwnershipCompilerPass
                [], // policies — filled by OwnershipCompilerPass
            ])
            ->setShared(true)->setPublic(true)
            ->addTag('kernel.event_subscriber');

        // Scope resolver registry
        $container->register(ScopeResolverRegistry::class, ScopeResolverRegistry::class)
            ->setArgument('$resolvers', [])
            ->setShared(true)->setPublic(true);

        // Redis-backed scoped + temporal stores
        if (extension_loaded('redis')) {
            $container->register(RedisScopedPermissionStore::class, RedisScopedPermissionStore::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(false);

            $container->setAlias(ScopedPermissionStoreInterface::class, RedisScopedPermissionStore::class)
                ->setPublic(false);

            $container->register(ScopedAuthorizationManager::class, ScopedAuthorizationManager::class)
                ->setArgument('$store', new Reference(ScopedPermissionStoreInterface::class))
                ->setShared(true)->setPublic(true);

            $container->register(RedisTemporalPermissionStore::class, RedisTemporalPermissionStore::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)->setPublic(false);

            $container->register(TemporalAuthorizationManager::class, TemporalAuthorizationManager::class)
                ->setArgument('$store', new Reference(RedisTemporalPermissionStore::class))
                ->setShared(true)->setPublic(true);
        }

        // Autoconfiguration
        $container->registerAttributeForAutoconfiguration(
            AsPolicy::class,
            static function (ChildDefinition $definition, AsPolicy $attribute): void {
                $definition->addTag('vortos.policy', ['resource' => $attribute->resource]);
                $definition->setPublic(true);
            },
        );
    }
}
