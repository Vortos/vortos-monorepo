<?php

declare(strict_types=1);

namespace Vortos\Audit\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Audit\Clock\SystemClock;
use Vortos\Audit\Console\AuditRetentionCommand;
use Vortos\Audit\Doctor\AuditDoctor;
use Vortos\Audit\Observability\AuditMetrics;
use Vortos\Audit\Retention\AuditArchiveWriterInterface;
use Vortos\Audit\Retention\AuditCheckpointStoreInterface;
use Vortos\Audit\Retention\AuditRetentionPolicy;
use Vortos\Audit\Retention\AuditRetentionSweeper;
use Vortos\Audit\Retention\ObjectStore\ObjectStoreArchiveWriter;
use Vortos\Audit\Retention\StoredAuditEventSerializer;
use Vortos\Audit\Storage\Dbal\DbalAuditStore;

/**
 * Wires the retention cold-archive target once the container is fully loaded.
 *
 * The archive writer needs vortos-object-store's {@see ImmediateObjectStoreInterface} alias,
 * whose presence can only be tested reliably AFTER every extension's load() has run — checking
 * it during AuditExtension::load() is extension-load-order dependent. This pass runs late, so
 * `hasAlias()` is authoritative: when the immediate object store is available it registers the
 * {@see ObjectStoreArchiveWriter} + {@see AuditRetentionSweeper}, points the retention command
 * at the real sweeper, and flips the doctor's `has_archive_target` fact; otherwise it does
 * nothing (the sweep safely refuses to purge un-archived data).
 */
final class AuditRetentionArchivePass implements CompilerPassInterface
{
    private const IMMEDIATE_OBJECT_STORE = 'Vortos\ObjectStore\Contract\ImmediateObjectStoreInterface';

    public function process(ContainerBuilder $container): void
    {
        // No store → no retention at all (registerRetention() never ran).
        if (!$container->hasDefinition(DbalAuditStore::class)
            || !$container->hasDefinition(AuditRetentionPolicy::class)) {
            return;
        }

        $iface = self::IMMEDIATE_OBJECT_STORE;
        if (!interface_exists($iface) || (!$container->hasAlias($iface) && !$container->has($iface))) {
            return; // no durable archive target
        }

        $keyPrefix = $container->hasParameter('vortos_audit.archive_key_prefix')
            ? (string) $container->getParameter('vortos_audit.archive_key_prefix')
            : 'audit-archive';
        $batchSize = $container->hasParameter('vortos_audit.retention_batch_size')
            ? (int) $container->getParameter('vortos_audit.retention_batch_size')
            : 1000;

        $container->register(ObjectStoreArchiveWriter::class, ObjectStoreArchiveWriter::class)
            ->setArgument('$objectStore', new Reference($iface))
            ->setArgument('$keyPrefix', $keyPrefix)
            ->setPublic(false);
        $container->setAlias(AuditArchiveWriterInterface::class, ObjectStoreArchiveWriter::class);

        $container->register(AuditRetentionSweeper::class, AuditRetentionSweeper::class)
            ->setArgument('$source', new Reference(DbalAuditStore::class))
            ->setArgument('$checkpoints', new Reference(AuditCheckpointStoreInterface::class))
            ->setArgument('$archiveWriter', new Reference(AuditArchiveWriterInterface::class))
            ->setArgument('$policy', new Reference(AuditRetentionPolicy::class))
            ->setArgument('$serializer', new Reference(StoredAuditEventSerializer::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setArgument('$batchSize', $batchSize)
            ->setArgument('$logger', new Reference('Psr\Log\LoggerInterface', ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setArgument('$metrics', new Reference(AuditMetrics::class, ContainerInterface::NULL_ON_INVALID_REFERENCE))
            ->setPublic(false);

        if ($container->hasDefinition(AuditRetentionCommand::class)) {
            $container->getDefinition(AuditRetentionCommand::class)
                ->setArgument('$sweeper', new Reference(AuditRetentionSweeper::class));
        }

        // Keep vortos:audit:doctor honest now that the archive target is wired.
        if ($container->hasDefinition(AuditDoctor::class)) {
            $doctor = $container->getDefinition(AuditDoctor::class);
            $facts  = $doctor->getArgument('$facts');
            if (\is_array($facts)) {
                $facts['has_archive_target'] = true;
                $doctor->setArgument('$facts', $facts);
            }
        }
    }
}
