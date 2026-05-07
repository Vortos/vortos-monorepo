<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\FeatureFlags\DependencyInjection\Compiler\FeatureFlagsCompilerPass;
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
    }
}
