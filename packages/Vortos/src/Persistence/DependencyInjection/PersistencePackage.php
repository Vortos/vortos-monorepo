<?php

declare(strict_types=1);

namespace Vortos\Persistence\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Container\Contract\PackageInterface;

/**
 * Core persistence package.
 *
 * Registers PersistenceExtension with the container.
 * No compiler passes are needed at the core persistence level —
 * the DBAL and MongoDB adapters handle their own service registration
 * entirely within their extension load() methods.
 *
 * Register in Container.php alongside other packages:
 *
 *   $packages = [
 *       new MessagingPackage(),
 *       new PersistencePackage(),
 *       new DbalPersistencePackage(),
 *       new MongoPersistencePackage(),
 *   ];
 */
final class PersistencePackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new PersistenceExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // No compiler passes needed at this stage.
    }
}
