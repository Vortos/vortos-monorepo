<?php

declare(strict_types=1);

namespace Vortos\OpsKit\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Foundation\Contract\PackageInterface;

/**
 * The ops-kit is shared primitives only — it owns no concern, so it registers no
 * collecting compiler pass of its own. Each concern (deploy, health, …) subclasses
 * {@see \Vortos\OpsKit\Driver\DependencyInjection\CollectDriversCompilerPass} and
 * registers it from its own Package::build().
 */
final class OpsKitPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new OpsKitExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // Intentionally empty: no concern, no pass. See class docblock.
    }
}
