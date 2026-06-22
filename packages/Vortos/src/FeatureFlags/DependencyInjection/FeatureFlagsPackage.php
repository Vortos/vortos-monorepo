<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\FeatureFlags\DependencyInjection\Compiler\ChangeRequestInterceptorCompilerPass;
use Vortos\FeatureFlags\DependencyInjection\Compiler\FeatureFlagsCompilerPass;
use Vortos\FeatureFlags\DependencyInjection\Compiler\FlagAuthzGateCompilerPass;
use Vortos\FeatureFlags\DependencyInjection\Compiler\FlagReadModelCompilerPass;
use Vortos\FeatureFlags\DependencyInjection\Compiler\FlagStorageCacheCompilerPass;
use Vortos\FeatureFlags\DependencyInjection\Compiler\ManagementAuthzGateCompilerPass;
use Vortos\Foundation\Contract\PackageInterface;

final class FeatureFlagsPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new FeatureFlagsExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // Runs after RouteCompilerPass (priority 80) so controllers are tagged,
        // before ResolveNamedArgumentsPass so $flagMap injection resolves correctly.
        $container->addCompilerPass(
            new FeatureFlagsCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            70,
        );

        // CacheInterface / Redis (CacheExtension/AuthExtension::load) are never
        // visible during FeatureFlagsExtension::load due to merge isolation.
        $container->addCompilerPass(
            new FlagStorageCacheCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
        );

        // Wire the Block 7 read models + projector when MongoDB is present. Priority 10 >
        // MongoReadRepositoryAutowirePass (8) so it registers the repos before that pass
        // fills their MongoStore '$store' argument.
        $container->addCompilerPass(
            new FlagReadModelCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            10,
        );

        // Upgrade the flag authz gate to the PolicyEngine-backed gate when Authorization
        // is present (otherwise the Null gate stays and requiredScope is inert).
        $container->addCompilerPass(
            new FlagAuthzGateCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
        );

        // Block 13 — upgrade management authz gate to PolicyEngine-backed when both
        // Authorization and Auth packages are present.
        $container->addCompilerPass(
            new ManagementAuthzGateCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
        );

        // Block 14 — replace the change-request interceptor stub with the real
        // policy-backed implementation.
        $container->addCompilerPass(
            new ChangeRequestInterceptorCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
        );
    }
}
