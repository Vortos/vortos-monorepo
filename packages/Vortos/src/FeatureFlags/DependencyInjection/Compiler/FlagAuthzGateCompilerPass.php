<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Auth\Identity\CurrentUserProvider;
use Vortos\Authorization\Engine\PolicyEngine;
use Vortos\FeatureFlags\Authz\FlagAuthzGateInterface;
use Vortos\FeatureFlags\Authz\PolicyEngineScopeChecker;
use Vortos\FeatureFlags\Authz\ScopeFlagAuthzGate;

/**
 * Upgrades the flag authz gate from the no-op default to the {@see PolicyEngine}-backed gate
 * when the Authorization engine (and the current-user provider it needs) are present.
 *
 * Apps without Authorization keep the {@see \Vortos\FeatureFlags\Authz\NullFlagAuthzGate} —
 * `requiredScope` is then inert. Cross-extension detection is reliable here because a
 * compiler pass sees the fully merged container.
 */
final class FlagAuthzGateCompilerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $hasPolicyEngine = $container->hasDefinition(PolicyEngine::class) || $container->hasAlias(PolicyEngine::class);
        $hasCurrentUser  = $container->hasDefinition(CurrentUserProvider::class) || $container->hasAlias(CurrentUserProvider::class);

        if (!$hasPolicyEngine || !$hasCurrentUser) {
            return;
        }

        $container->setDefinition(
            PolicyEngineScopeChecker::class,
            (new Definition(PolicyEngineScopeChecker::class))
                ->setArgument('$policy', new Reference(PolicyEngine::class))
                ->setArgument('$currentUser', new Reference(CurrentUserProvider::class))
                ->setPublic(false),
        );

        $container->setDefinition(
            ScopeFlagAuthzGate::class,
            (new Definition(ScopeFlagAuthzGate::class))
                ->setArgument('$checker', new Reference(PolicyEngineScopeChecker::class))
                ->setShared(true)
                ->setPublic(false),
        );

        $container->setAlias(FlagAuthzGateInterface::class, ScopeFlagAuthzGate::class)
            ->setPublic(false);
    }
}
