<?php

declare(strict_types=1);

namespace Vortos\Cache\DependencyInjection;

use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Container\Contract\PackageInterface;

/**
 * Cache package.
 *
 * No compiler passes needed — all wiring happens in CacheExtension::load().
 *
 * ## Load order in Container.php
 *
 * CachePackage MUST be first in the packages array.
 * MessagingPackage (idempotency via CacheInterface) and CqrsPackage
 * (command idempotency via CacheInterface) both depend on CacheInterface
 * being registered before their extensions run.
 *
 *   $packages = [
 *       new CachePackage(),          // first — registers CacheInterface
 *       new MessagingPackage(),       // uses CacheInterface for idempotency
 *       new TracingPackage(),
 *       new PersistencePackage(),
 *       new DbalPersistencePackage(),
 *       new MongoPersistencePackage(),
 *       new CqrsPackage(),            // uses CacheInterface for command idempotency
 *   ];
 */
final class CachePackage implements PackageInterface
{
    public function getContainerExtension(): ?ExtensionInterface
    {
        return new CacheExtension();
    }

    public function build(ContainerBuilder $container): void
    {
        // No compiler passes needed at this stage.
        // CacheWarmerPass would go here when implemented.
    }
}
