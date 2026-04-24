<?php

declare(strict_types=1);

namespace Vortos\Authorization\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Authorization\DependencyInjection\Compiler\PolicyRegistryPass;
use Vortos\Container\Contract\PackageInterface;

/**
 * Authorization package.
 *
 * Add to Container.php after AuthPackage:
 *
 *   $packages = [
 *       new CachePackage(),
 *       new AuthPackage(),
 *       new AuthorizationPackage(),
 *       // ...
 *   ];
 *
 * Compiler pass priorities:
 *   PolicyRegistryPass — 50 (after autoconfiguration)
 */
final class AuthorizationPackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new AuthorizationExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass(
            new PolicyRegistryPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            50,
        );
    }
}
