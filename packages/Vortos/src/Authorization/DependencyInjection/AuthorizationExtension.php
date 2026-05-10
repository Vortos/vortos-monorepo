<?php
declare(strict_types=1);

namespace Vortos\Authorization\DependencyInjection;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\DependencyInjection\ChildDefinition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Authorization\Admin\RolePermissionAdminService;
use Vortos\Authorization\Admin\UserRoleAdminService;
use Vortos\Authorization\Audit\AuthorizationAuditContextProvider;
use Vortos\Authorization\Command\AuthAssignUserRoleCommand;
use Vortos\Authorization\Command\AuthCanCommand;
use Vortos\Authorization\Command\AuthCommandIdentityFactory;
use Vortos\Authorization\Command\AuthExplainCommand;
use Vortos\Authorization\Command\AuthGrantRolePermissionCommand;
use Vortos\Authorization\Command\AuthPermissionsCommand;
use Vortos\Authorization\Command\AuthRemoveUserRoleCommand;
use Vortos\Authorization\Command\AuthRolesCommand;
use Vortos\Authorization\Command\AuthRevokeRolePermissionCommand;
use Vortos\Authorization\Command\AuthSeedCommand;
use Vortos\Authorization\Attribute\AsPolicy;
use Vortos\Authorization\Attribute\PermissionCatalog;
use Vortos\Authorization\Contract\AuthorizationAuditStoreInterface;
use Vortos\Authorization\Contract\AuthorizationCacheInvalidatorInterface;
use Vortos\Authorization\Contract\AuthorizationVersionStoreInterface;
use Vortos\Authorization\Contract\EmergencyDenyListInterface;
use Vortos\Authorization\Contract\PolicyRegistryInterface;
use Vortos\Authorization\Contract\PermissionRegistryInterface;
use Vortos\Authorization\Contract\PermissionResolverInterface;
use Vortos\Authorization\Contract\RolePermissionStoreInterface;
use Vortos\Authorization\Contract\UserRoleStoreInterface;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\Authorization\Engine\PolicyRegistry;
use Vortos\Authorization\Middleware\AuthorizationMiddleware;
use Vortos\Authorization\Middleware\ControllerPermissionMap;
use Vortos\Authorization\Ownership\Middleware\OwnershipMiddleware;
use Vortos\Authorization\Permission\PermissionRegistry;
use Vortos\Authorization\Resolver\CachedPermissionResolver;
use Vortos\Authorization\Resolver\CachedPermissionInvalidator;
use Vortos\Authorization\Resolver\DatabasePermissionResolver;
use Vortos\Authorization\Resolver\NullAuthorizationCacheInvalidator;
use Vortos\Authorization\Resolver\RequestMemoizedPermissionResolver;
use Vortos\Authorization\Resolver\RoleGenerationStore;
use Vortos\Authorization\Scope\Contract\ScopedPermissionStoreInterface;
use Vortos\Authorization\Scope\Contract\ScopeMode;
use Vortos\Authorization\Scope\ScopeResolverRegistry;
use Vortos\Authorization\Scope\ScopedAuthorizationManager;
use Vortos\Authorization\Scope\Storage\NullScopedPermissionStore;
use Vortos\Authorization\Scope\Storage\RedisScopedPermissionStore;
use Vortos\Authorization\Storage\DbalRolePermissionStore;
use Vortos\Authorization\Storage\DbalUserRoleStore;
use Vortos\Authorization\Storage\DbalAuthorizationAuditStore;
use Vortos\Authorization\Storage\GenerationalRolePermissionStore;
use Vortos\Authorization\Storage\NullAuthorizationVersionStore;
use Vortos\Authorization\Storage\NullEmergencyDenyList;
use Vortos\Authorization\Storage\RedisAuthorizationVersionStore;
use Vortos\Authorization\Storage\RedisEmergencyDenyList;
use Vortos\Authorization\Temporal\Contract\TemporalPermissionStoreInterface;
use Vortos\Authorization\Temporal\Storage\NullTemporalPermissionStore;
use Vortos\Authorization\Temporal\Storage\RedisTemporalPermissionStore;
use Vortos\Authorization\Temporal\TemporalAuthorizationManager;
use Vortos\Authorization\Tracing\AuthorizationTracer;
use Vortos\Authorization\Http\PermissionsController;
use Vortos\Authorization\Voter\RoleVoter;
use Vortos\Config\DependencyInjection\ConfigExtension;
use Vortos\Config\Stub\ConfigStub;

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
            ->setArgument('$resolver', new Reference(PermissionResolverInterface::class))
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

        $container->register(PermissionRegistry::class, PermissionRegistry::class)
            ->setArgument('$permissions', [])
            ->setArgument('$defaultGrants', [])
            ->setShared(true)->setPublic(true);
        $container->setAlias(PermissionRegistryInterface::class, PermissionRegistry::class)->setPublic(true);

        $tracingReference = interface_exists('Vortos\\Tracing\\Contract\\TracingInterface')
            && $container->hasAlias('Vortos\\Tracing\\Contract\\TracingInterface')
                ? new Reference('Vortos\\Tracing\\Contract\\TracingInterface')
                : null;

        $container->register(AuthorizationTracer::class, AuthorizationTracer::class)
            ->setArgument('$tracer', $tracingReference)
            ->setArgument('$traceDecisions', $resolved['trace_decisions'])
            ->setArgument('$traceResolver', $resolved['trace_resolver'])
            ->setArgument('$traceAdminMutations', $resolved['trace_admin_mutations'])
            ->setShared(true)
            ->setPublic(false);

        $container->register(AuthorizationAuditContextProvider::class, AuthorizationAuditContextProvider::class)
            ->setArgument('$requestStack', $container->hasDefinition(RequestStack::class) || $container->hasAlias(RequestStack::class)
                ? new Reference(RequestStack::class)
                : null)
            ->setArgument('$tracer', $tracingReference)
            ->setShared(true)
            ->setPublic(false);

        $container->register(PolicyEngine::class, PolicyEngine::class)
            ->setArgument('$registry', new Reference(PolicyRegistryInterface::class))
            ->setArgument('$permissionRegistry', new Reference(PermissionRegistryInterface::class))
            ->setArgument('$resolver', new Reference(PermissionResolverInterface::class))
            ->setArgument('$denyList', new Reference(EmergencyDenyListInterface::class))
            ->setArgument('$versionStore', new Reference(AuthorizationVersionStoreInterface::class))
            ->setArgument('$roleVoter', new Reference(RoleVoter::class))
            ->setArgument('$authzVersionCheck', $resolved['authz_version_check'])
            ->setArgument('$breakGlassBypass', $resolved['break_glass_bypass'])
            ->setArgument('$breakGlassRole', $resolved['break_glass_role'])
            ->setArgument('$scopedPermissions', null)
            ->setArgument('$tracer', new Reference(AuthorizationTracer::class))
            ->setShared(true)->setPublic(true);

        $container->register(ControllerPermissionMap::class, ControllerPermissionMap::class)
            ->setArgument('$map', [])
            ->setShared(true)->setPublic(false);

        $container->register(DbalRolePermissionStore::class, DbalRolePermissionStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(DbalUserRoleStore::class, DbalUserRoleStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(UserRoleStoreInterface::class, DbalUserRoleStore::class)
            ->setPublic(false);

        $container->register(DbalAuthorizationAuditStore::class, DbalAuthorizationAuditStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setShared(true)
            ->setPublic(false);
        $container->setAlias(AuthorizationAuditStoreInterface::class, DbalAuthorizationAuditStore::class)
            ->setPublic(false);

        $container->register(AuthSeedCommand::class, AuthSeedCommand::class)
            ->setArgument('$registry', new Reference(PermissionRegistryInterface::class))
            ->setArgument('$connection', new Reference(Connection::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        $container->register(AuthCommandIdentityFactory::class, AuthCommandIdentityFactory::class)
            ->setArgument('$userRoles', new Reference(UserRoleStoreInterface::class))
            ->setArgument('$versions', new Reference(AuthorizationVersionStoreInterface::class))
            ->setShared(true)
            ->setPublic(false);

        $container->register(AuthPermissionsCommand::class, AuthPermissionsCommand::class)
            ->setArgument('$registry', new Reference(PermissionRegistryInterface::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        $container->register(AuthRolesCommand::class, AuthRolesCommand::class)
            ->setArgument('$userRoles', new Reference(UserRoleStoreInterface::class))
            ->setArgument('$roleVoter', new Reference(RoleVoter::class))
            ->setArgument('$versions', new Reference(AuthorizationVersionStoreInterface::class))
            ->setArgument('$denyList', new Reference(EmergencyDenyListInterface::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        // AuthorizationMiddleware
        $container->register(AuthorizationMiddleware::class, AuthorizationMiddleware::class)
            ->setArguments([
                new Reference(PolicyEngine::class),
                new Reference(\Vortos\Auth\Identity\CurrentUserProvider::class),
                new Reference(ControllerPermissionMap::class),
            ])
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
        if ($container->hasDefinition(\Redis::class)) {
            $container->register(RedisEmergencyDenyList::class, RedisEmergencyDenyList::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)
                ->setPublic(false);
            $container->setAlias(EmergencyDenyListInterface::class, RedisEmergencyDenyList::class)
                ->setPublic(false);

            $container->register(RedisAuthorizationVersionStore::class, RedisAuthorizationVersionStore::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)
                ->setPublic(false);
            $container->setAlias(AuthorizationVersionStoreInterface::class, RedisAuthorizationVersionStore::class)
                ->setPublic(false);

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
            $container->setAlias(TemporalPermissionStoreInterface::class, RedisTemporalPermissionStore::class)
                ->setPublic(false);

            $container->register(TemporalAuthorizationManager::class, TemporalAuthorizationManager::class)
                ->setArgument('$store', new Reference(RedisTemporalPermissionStore::class))
                ->setShared(true)->setPublic(true);
        } else {
            $container->register(NullScopedPermissionStore::class, NullScopedPermissionStore::class)
                ->setShared(true)
                ->setPublic(false);
            $container->setAlias(ScopedPermissionStoreInterface::class, NullScopedPermissionStore::class)
                ->setPublic(false);

            $container->register(ScopedAuthorizationManager::class, ScopedAuthorizationManager::class)
                ->setArgument('$store', new Reference(ScopedPermissionStoreInterface::class))
                ->setShared(true)->setPublic(true);

            $container->register(NullEmergencyDenyList::class, NullEmergencyDenyList::class)
                ->setShared(true)
                ->setPublic(false);
            $container->setAlias(EmergencyDenyListInterface::class, NullEmergencyDenyList::class)
                ->setPublic(false);

            $container->register(NullAuthorizationVersionStore::class, NullAuthorizationVersionStore::class)
                ->setShared(true)
                ->setPublic(false);
            $container->setAlias(AuthorizationVersionStoreInterface::class, NullAuthorizationVersionStore::class)
                ->setPublic(false);

            $container->register(NullTemporalPermissionStore::class, NullTemporalPermissionStore::class)
                ->setShared(true)
                ->setPublic(false);
            $container->setAlias(TemporalPermissionStoreInterface::class, NullTemporalPermissionStore::class)
                ->setPublic(false);

            $container->register(TemporalAuthorizationManager::class, TemporalAuthorizationManager::class)
                ->setArgument('$store', new Reference(TemporalPermissionStoreInterface::class))
                ->setShared(true)
                ->setPublic(true);
        }

        $container->getDefinition(PolicyEngine::class)
            ->setArgument('$scopedPermissions', new Reference(ScopedPermissionStoreInterface::class));

        if ($container->hasDefinition(\Redis::class)) {
            $container->register(RoleGenerationStore::class, RoleGenerationStore::class)
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setShared(true)
                ->setPublic(false);

            $container->register(GenerationalRolePermissionStore::class, GenerationalRolePermissionStore::class)
                ->setArgument('$inner', new Reference(DbalRolePermissionStore::class))
                ->setArgument('$generations', new Reference(RoleGenerationStore::class))
                ->setShared(true)
                ->setPublic(false);
            $container->setAlias(RolePermissionStoreInterface::class, GenerationalRolePermissionStore::class)
                ->setPublic(false);
        } else {
            $container->setAlias(RolePermissionStoreInterface::class, DbalRolePermissionStore::class)
                ->setPublic(false);
        }

        $container->register(DatabasePermissionResolver::class, DatabasePermissionResolver::class)
            ->setArgument('$userRoleStore', new Reference(UserRoleStoreInterface::class))
            ->setArgument('$rolePermissionStore', new Reference(RolePermissionStoreInterface::class))
            ->setArgument('$roleVoter', new Reference(RoleVoter::class))
            ->setArgument(
                '$temporalStore',
                $container->hasAlias(TemporalPermissionStoreInterface::class)
                    ? new Reference(TemporalPermissionStoreInterface::class)
                    : null,
            )
            ->setArgument('$tracer', new Reference(AuthorizationTracer::class))
            ->setShared(true)
            ->setPublic(false);

        $innerResolver = DatabasePermissionResolver::class;

        if ($container->hasDefinition(\Redis::class)) {
            $container->register(CachedPermissionResolver::class, CachedPermissionResolver::class)
                ->setArgument('$inner', new Reference(DatabasePermissionResolver::class))
                ->setArgument('$redis', new Reference(\Redis::class))
                ->setArgument('$generations', new Reference(RoleGenerationStore::class))
                ->setArgument('$tracer', new Reference(AuthorizationTracer::class))
                ->setShared(true)
                ->setPublic(false);
            $container->register(CachedPermissionInvalidator::class, CachedPermissionInvalidator::class)
                ->setArgument('$resolver', new Reference(CachedPermissionResolver::class))
                ->setShared(true)
                ->setPublic(false);
            $container->setAlias(AuthorizationCacheInvalidatorInterface::class, CachedPermissionInvalidator::class)
                ->setPublic(false);
            $innerResolver = CachedPermissionResolver::class;
        } else {
            $container->register(NullAuthorizationCacheInvalidator::class, NullAuthorizationCacheInvalidator::class)
                ->setShared(true)
                ->setPublic(false);
            $container->setAlias(AuthorizationCacheInvalidatorInterface::class, NullAuthorizationCacheInvalidator::class)
                ->setPublic(false);
        }

        $container->register(RequestMemoizedPermissionResolver::class, RequestMemoizedPermissionResolver::class)
            ->setArgument('$inner', new Reference($innerResolver))
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(PermissionResolverInterface::class, RequestMemoizedPermissionResolver::class)
            ->setPublic(true);

        $container->register(RolePermissionAdminService::class, RolePermissionAdminService::class)
            ->setArgument('$store', new Reference(RolePermissionStoreInterface::class))
            ->setArgument('$audit', new Reference(AuthorizationAuditStoreInterface::class))
            ->setArgument('$registry', new Reference(PermissionRegistryInterface::class))
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$tracer', new Reference(AuthorizationTracer::class))
            ->setArgument('$auditContext', new Reference(AuthorizationAuditContextProvider::class))
            ->setShared(true)
            ->setPublic(true);

        $container->register(UserRoleAdminService::class, UserRoleAdminService::class)
            ->setArgument('$store', new Reference(UserRoleStoreInterface::class))
            ->setArgument('$audit', new Reference(AuthorizationAuditStoreInterface::class))
            ->setArgument('$versions', new Reference(AuthorizationVersionStoreInterface::class))
            ->setArgument('$cache', new Reference(AuthorizationCacheInvalidatorInterface::class))
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$tracer', new Reference(AuthorizationTracer::class))
            ->setArgument('$auditContext', new Reference(AuthorizationAuditContextProvider::class))
            ->setShared(true)
            ->setPublic(true);

        $container->register(AuthAssignUserRoleCommand::class, AuthAssignUserRoleCommand::class)
            ->setArgument('$admin', new Reference(UserRoleAdminService::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        $container->register(AuthRemoveUserRoleCommand::class, AuthRemoveUserRoleCommand::class)
            ->setArgument('$admin', new Reference(UserRoleAdminService::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        $container->register(AuthGrantRolePermissionCommand::class, AuthGrantRolePermissionCommand::class)
            ->setArgument('$admin', new Reference(RolePermissionAdminService::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        $container->register(AuthRevokeRolePermissionCommand::class, AuthRevokeRolePermissionCommand::class)
            ->setArgument('$admin', new Reference(RolePermissionAdminService::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        $container->register(AuthCanCommand::class, AuthCanCommand::class)
            ->setArgument('$identityFactory', new Reference(AuthCommandIdentityFactory::class))
            ->setArgument('$engine', new Reference(PolicyEngine::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        $container->register(AuthExplainCommand::class, AuthExplainCommand::class)
            ->setArgument('$identityFactory', new Reference(AuthCommandIdentityFactory::class))
            ->setArgument('$engine', new Reference(PolicyEngine::class))
            ->setArgument('$resolver', new Reference(PermissionResolverInterface::class))
            ->setArgument('$userRoles', new Reference(UserRoleStoreInterface::class))
            ->setArgument('$rolePermissions', new Reference(RolePermissionStoreInterface::class))
            ->setArgument('$versions', new Reference(AuthorizationVersionStoreInterface::class))
            ->setArgument('$denyList', new Reference(EmergencyDenyListInterface::class))
            ->addTag('console.command')
            ->setShared(true)
            ->setPublic(false);

        // Autoconfiguration
        $container->registerAttributeForAutoconfiguration(
            AsPolicy::class,
            static function (ChildDefinition $definition, AsPolicy $attribute): void {
                $definition->addTag('vortos.policy', ['resource' => $attribute->resource]);
                $definition->setPublic(true);
            },
        );

        $container->registerAttributeForAutoconfiguration(
            PermissionCatalog::class,
            static function (ChildDefinition $definition, PermissionCatalog $attribute): void {
                $definition->addTag('vortos.permission_catalog', [
                    'resource' => $attribute->resource,
                    'group' => $attribute->group,
                ]);
                $definition->setPublic(false);
            },
        );

        $container->register('vortos.config_stub.authorization', ConfigStub::class)
            ->setArguments(['authorization', __DIR__ . '/../stubs/authorization.php'])
            ->addTag(ConfigExtension::STUB_TAG)
            ->setPublic(false);
    }
}
