<?php

declare(strict_types=1);

namespace Vortos\Debug\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Debug\DependencyInjection\Compiler\DebugContainerPass;
use Vortos\Debug\DependencyInjection\Compiler\DebugRoutesPass;
use Vortos\Foundation\Contract\PackageInterface;

final class DebugPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new DebugExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // Runs after RouteCompilerPass (priority 80) — collects route metadata
        $container->addCompilerPass(
            new DebugRoutesPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            70,
        );

        // Must run before ResolveNamedArgumentsPass (priority -1000) converts $name → positional index
        $container->addCompilerPass(
            new DebugContainerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            60,
        );
    }
}
