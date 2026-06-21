<?php

declare(strict_types=1);

namespace Vortos\ObjectStore\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Vortos\Foundation\Contract\PackageInterface;
use Vortos\ObjectStore\DependencyInjection\Compiler\MiddlewareCompilerPass;
use Vortos\ObjectStore\DependencyInjection\Compiler\ObjectStoreRuntimeDependenciesPass;

/**
 * Object storage package.
 *
 * Load after LoggerPackage. Later phases also integrate with CachePackage,
 * TracingPackage, MetricsPackage, and PersistenceDbalPackage.
 */
final class ObjectStorePackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new ObjectStoreExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(new MiddlewareCompilerPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, 80);
        // Wires Clock/Metrics/Tracing dependencies and default policy/router aliases
        // that are invisible to ObjectStoreExtension::load due to merge isolation.
        $container->addCompilerPass(new ObjectStoreRuntimeDependenciesPass(), PassConfig::TYPE_BEFORE_OPTIMIZATION);
    }
}
