<?php

declare(strict_types=1);

namespace Vortos\Cache\DependencyInjection;

use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Vortos\Cache\Tracing\CacheTracingCompilerPass;
use Vortos\Foundation\Contract\PackageInterface;

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
        // Wraps the active cache adapter with TracingCacheAdapter when TracingInterface is available.
        // Runs after all extensions load so TracingInterface is guaranteed to be defined.
        $container->addCompilerPass(
            new CacheTracingCompilerPass(),
            PassConfig::TYPE_BEFORE_OPTIMIZATION,
            0,
        );
    }
}
