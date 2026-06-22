<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\FeatureFlags\Authz\Management\ManagementAuthzGateInterface;
use Vortos\FeatureFlags\Authz\Management\PolicyEngineManagementAuthzGate;

/**
 * Upgrades the management authz gate from the no-op default to the PolicyEngine-backed
 * gate when both Authorization and Auth packages are present.
 */
final class ManagementAuthzGateCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $hasPolicyEngine = $container->hasDefinition(PolicyEngine::class) || $container->hasAlias(PolicyEngine::class);
        $hasCurrentUser  = $container->hasDefinition(CurrentUserProvider::class) || $container->hasAlias(CurrentUserProvider::class);

        if (!$hasPolicyEngine || !$hasCurrentUser) {
            return;
        }

        $container->setDefinition(
            PolicyEngineManagementAuthzGate::class,
            (new Definition(PolicyEngineManagementAuthzGate::class))
                ->setArgument('$policy', new Reference(PolicyEngine::class))
                ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
                ->setPublic(false),
        );

        $container->setAlias(ManagementAuthzGateInterface::class, PolicyEngineManagementAuthzGate::class)
            ->setPublic(false);
    }
}
