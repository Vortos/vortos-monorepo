<?php

declare(strict_types=1);

namespace Vortos\Health\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Health\Monitor\DbalSyncRecordStore;
use Vortos\Health\Monitor\SyncRecordStoreInterface;

/**
 * Upgrades the uptime sync record from the in-memory fallback to DBAL once every extension has
 * loaded.
 *
 * HealthExtension::load() chose between the two with `$container->has(Connection::class)`. The
 * Connection is registered by vortos-persistence's extension, and Symfony gives no ordering
 * guarantee between extension loads, so that check is a race — the fact that the CLASS belongs to
 * Doctrine rather than to a vortos package changes nothing about who registers the SERVICE.
 *
 * It lost that race in production. `health:monitor:sync` records the payload hash it last pushed so
 * a subsequent run is a no-op; backed by InMemorySyncRecordStore that record dies with the process,
 * so every invocation saw no previous hash and re-pushed the monitor. The command could never
 * converge, and `vortos.uptime_sync` stayed empty while the external monitor was maintained by hand.
 *
 * The in-memory fallback remains correct for a container with genuinely no database (unit tests,
 * CLI tools); this pass simply repairs the case where a database exists and the extension could not
 * yet see it.
 */
final class DurableSyncRecordStorePass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(Connection::class)) {
            return; // genuinely no database — the in-memory fallback is the right answer.
        }

        if ($container->hasDefinition(DbalSyncRecordStore::class)) {
            return; // the extension already won its race; nothing to repair.
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        $container->register(DbalSyncRecordStore::class, DbalSyncRecordStore::class)
            ->setArgument('$connection', new Reference(Connection::class))
            ->setArgument('$table', $prefix . 'uptime_sync')
            ->setPublic(false);

        $container->setAlias(SyncRecordStoreInterface::class, DbalSyncRecordStore::class)->setPublic(false);
    }
}
