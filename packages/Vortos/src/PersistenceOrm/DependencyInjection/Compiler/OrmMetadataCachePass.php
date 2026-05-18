<?php

declare(strict_types=1);

namespace Vortos\PersistenceOrm\DependencyInjection\Compiler;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Cache\Contract\TaggedCacheInterface;
use Vortos\PersistenceOrm\Cache\OrmMetadataCache;
use Vortos\PersistenceOrm\Command\OrmClearCacheCommand;
use Vortos\PersistenceOrm\Factory\EntityManagerFactory;

/**
 * Wires OrmMetadataCache into the EntityManager factory at compile time.
 *
 * This pass runs after all extensions have been merged into the main container,
 * so it can safely check whether TaggedCacheInterface is registered (by
 * VortosCache) without the false-negative caused by MergeExtensionConfigurationPass
 * isolating each extension into its own temporary container during load().
 *
 * In production with cache available: arg 3 of EntityManagerFactory::fromDsn()
 * is set to OrmMetadataCache, dramatically reducing annotation parsing overhead.
 *
 * Without cache (dev mode or cache package absent): arg 3 remains null and a
 * warning is logged so operators can diagnose missing cache in production.
 */
final class OrmMetadataCachePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(\Vortos\PersistenceOrm\Transaction\OrmUnitOfWork::class)) {
            // PersistenceOrmExtension was not loaded — nothing to do.
            return;
        }

        $emDefinition = $container->findDefinition(\Doctrine\ORM\EntityManager::class);

        if ($container->has(TaggedCacheInterface::class)) {
            $container->register(OrmMetadataCache::class, OrmMetadataCache::class)
                ->setArgument('$cache', new Reference(TaggedCacheInterface::class))
                ->setShared(true)
                ->setPublic(false);

            // Arg 3 of EntityManagerFactory::fromDsn($dsn, $paths, $devMode, $metadataCache)
            $emDefinition->setArgument(3, new Reference(OrmMetadataCache::class));

            $container->register(OrmClearCacheCommand::class, OrmClearCacheCommand::class)
                ->setArgument('$cache', new Reference(TaggedCacheInterface::class))
                ->setPublic(false)
                ->addTag('console.command');

            return;
        }

        $env = $container->getParameter('kernel.env');

        if ($env === 'prod') {
            if ($container->has(LoggerInterface::class)) {
                // Log a warning at boot that metadata cache is unavailable in prod.
                // Handled via container parameter so the logger service is not
                // instantiated during compilation — just stored for runtime use.
                $container->setParameter('vortos.orm.cache_warning', true);
            } else {
                error_log('[Vortos] OrmMetadataCache: TaggedCacheInterface not found in prod — ORM metadata will be parsed on every request. Register the VortosCache package to enable caching.');
            }
        }
    }
}
