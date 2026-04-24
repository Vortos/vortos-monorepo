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
use Vortos\Authorization\Voter\RoleVoter;

/**
 * Wires all authorization services.
 *
 * ## Services registered
 *
 *   RoleVoter                    — role hierarchy voter, injectable in policies
 *   PolicyRegistry               — ServiceLocator of all registered policies
 *   PolicyRegistryInterface      — alias to PolicyRegistry
 *   PolicyEngine                 — central authorization check (can/authorize)
 *   AuthorizationMiddleware      — event subscriber, kernel.request priority 5
 *
 * ## Policy autoconfiguration
 *
 *   #[AsPolicy(resource: 'athletes')] → tagged 'vortos.policy' with resource attribute
 *   Discovered by PolicyRegistryPass
 */
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

        // RoleVoter — injectable in policies
        $container->register(RoleVoter::class, RoleVoter::class)
            ->setArguments([$resolved['role_hierarchy']])
            ->setShared(true)
            ->setPublic(true);

        // Policy ServiceLocator — filled by PolicyRegistryPass
        $container->register('vortos.authorization.policy_locator', ServiceLocator::class)
            ->setArguments([[]])
            ->addTag('container.service_locator')
            ->setPublic(false);

        // PolicyRegistry
        $container->register(PolicyRegistry::class, PolicyRegistry::class)
            ->setArgument('$policies', new Reference('vortos.authorization.policy_locator'))
            ->setShared(true)
            ->setPublic(false);

        $container->setAlias(PolicyRegistryInterface::class, PolicyRegistry::class)
            ->setPublic(false);

        // PolicyEngine
        $container->register(PolicyEngine::class, PolicyEngine::class)
            ->setArgument('$registry', new Reference(PolicyRegistryInterface::class))
            ->setShared(true)
            ->setPublic(true);

        // AuthorizationMiddleware
        $container->register(AuthorizationMiddleware::class, AuthorizationMiddleware::class)
            ->setArguments([
                new Reference(PolicyEngine::class),
                new Reference(\Vortos\Auth\Identity\CurrentUserProvider::class),
            ])
            ->setShared(true)
            ->setPublic(true)
            ->addTag('kernel.event_subscriber');

        // Autoconfiguration — #[AsPolicy] → tag 'vortos.policy'
        $container->registerAttributeForAutoconfiguration(
            AsPolicy::class,
            static function (ChildDefinition $definition, AsPolicy $attribute): void {
                $definition->addTag('vortos.policy', ['resource' => $attribute->resource]);
                $definition->setPublic(true);
            },
        );
    }
}
