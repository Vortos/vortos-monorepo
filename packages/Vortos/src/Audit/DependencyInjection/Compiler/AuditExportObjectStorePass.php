<?php

declare(strict_types=1);

namespace Vortos\Audit\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Audit\Clock\SystemClock;
use Vortos\Audit\Console\AuditExportGcCommand;
use Vortos\Audit\Doctor\AuditDoctor;
use Vortos\Audit\Export\AuditExportEnqueuer;
use Vortos\Audit\Export\AuditExportGarbageCollector;
use Vortos\Audit\Export\AuditExportJobStoreInterface;
use Vortos\Audit\Export\AuditExportRequestHandler;
use Vortos\Audit\Export\AuditExportService;
use Vortos\Audit\Export\AuditExportSinkInterface;
use Vortos\Audit\Export\ObjectStore\ObjectStoreExportSink;
use Vortos\Audit\Export\StreamingAuditExporter;
use Vortos\Audit\Integrity\AuditHashChain;
use Vortos\Audit\Observability\AuditMetrics;
use Vortos\Audit\Query\AuditQueryInterface;
use Vortos\Audit\Retention\StoredAuditEventSerializer;

/**
 * Wires the async-export object-store target once the container is fully loaded — the exact
 * counterpart to {@see AuditRetentionArchivePass}. The export sink needs vortos-object-store's
 * {@see ImmediateObjectStoreInterface} alias (which also mints the presigned download URLs),
 * and that alias can only be tested reliably AFTER every extension's load() has run. When it is
 * present this pass registers the sink, the {@see StreamingAuditExporter}, the app-facing
 * {@see AuditExportService}, the {@see AuditExportGarbageCollector}, and — only when async export
 * is enabled — the consumer {@see AuditExportRequestHandler}; and flips the doctor's
 * `has_export_target` fact. Without an object store it does nothing (export stays unavailable,
 * and the job store simply never advances past Queued).
 */
final class AuditExportObjectStorePass implements CompilerPassInterface
{
    private const IMMEDIATE_OBJECT_STORE = 'Vortos\ObjectStore\Contract\ImmediateObjectStoreInterface';

    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(AuditExportJobStoreInterface::class)
            && !$container->hasDefinition(AuditExportJobStoreInterface::class)) {
            return; // export not registered at all (no DBAL)
        }

        $iface = self::IMMEDIATE_OBJECT_STORE;
        if (!interface_exists($iface) || (!$container->hasAlias($iface) && !$container->has($iface))) {
            return; // no object-store target → export cannot run
        }

        $hmacKey       = $this->param($container, 'vortos_audit.export_hmac_key', '');
        $keyPrefix     = $this->param($container, 'vortos_audit.export_key_prefix', 'audit-exports');
        $pageSize      = (int) $this->param($container, 'vortos_audit.export_page_size', '500');
        $retentionDays = (int) $this->param($container, 'vortos_audit.export_artifact_retention_days', '7');
        $downloadTtl   = (int) $this->param($container, 'vortos_audit.export_download_url_ttl_seconds', '900');
        $exportAsync   = $container->hasParameter('vortos_audit.export_async')
            && (bool) $container->getParameter('vortos_audit.export_async');

        $nullRef = ContainerInterface::NULL_ON_INVALID_REFERENCE;

        // Sink: object-store put + presigned download URL + delete.
        $container->register(ObjectStoreExportSink::class, ObjectStoreExportSink::class)
            ->setArgument('$objectStore', new Reference($iface))
            ->setPublic(false);
        $container->setAlias(AuditExportSinkInterface::class, ObjectStoreExportSink::class);

        // Streaming exporter: walks the query reader and streams NDJSON to the sink.
        $container->register(StreamingAuditExporter::class, StreamingAuditExporter::class)
            ->setArgument('$query', new Reference(AuditQueryInterface::class))
            ->setArgument('$serializer', new Reference(StoredAuditEventSerializer::class))
            ->setArgument('$chain', new Reference(AuditHashChain::class))
            ->setArgument('$sink', new Reference(AuditExportSinkInterface::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setArgument('$hmacKey', $hmacKey)
            ->setArgument('$keyPrefix', $keyPrefix)
            ->setArgument('$pageSize', $pageSize)
            ->setArgument('$downloadTtlSeconds', $downloadTtl)
            ->setPublic(false);

        // App-facing coordinator (enqueue when async, status, list, fresh download URL). The
        // enqueuer is optional here so the service is still constructible for read-only paths
        // (status/list/download) even if async dispatch is off.
        $container->register(AuditExportService::class, AuditExportService::class)
            ->setArgument('$enqueuer', new Reference(AuditExportEnqueuer::class, $nullRef))
            ->setArgument('$jobs', new Reference(AuditExportJobStoreInterface::class))
            ->setArgument('$sink', new Reference(AuditExportSinkInterface::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setArgument('$downloadUrlTtlSeconds', $downloadTtl)
            ->setPublic(true);

        // Retention GC of aged artifacts.
        $container->register(AuditExportGarbageCollector::class, AuditExportGarbageCollector::class)
            ->setArgument('$jobs', new Reference(AuditExportJobStoreInterface::class))
            ->setArgument('$sink', new Reference(AuditExportSinkInterface::class))
            ->setArgument('$clock', new Reference(SystemClock::class))
            ->setArgument('$logger', new Reference('Psr\Log\LoggerInterface', $nullRef))
            ->setArgument('$metrics', new Reference(AuditMetrics::class, $nullRef))
            ->setPublic(false);

        if ($container->hasDefinition(AuditExportGcCommand::class)) {
            $container->getDefinition(AuditExportGcCommand::class)
                ->setArgument('$collector', new Reference(AuditExportGarbageCollector::class));
        }

        // Consumer handler: only when async export is enabled (app declared the consumer).
        if ($exportAsync) {
            $container->register(AuditExportRequestHandler::class, AuditExportRequestHandler::class)
                ->setArgument('$jobs', new Reference(AuditExportJobStoreInterface::class))
                ->setArgument('$exporter', new Reference(StreamingAuditExporter::class))
                ->setArgument('$clock', new Reference(SystemClock::class))
                ->setArgument('$artifactRetentionSeconds', $retentionDays * 86400)
                ->setArgument('$notifier', new Reference('Vortos\Audit\Export\AuditExportNotifierInterface', $nullRef))
                ->setArgument('$logger', new Reference('Psr\Log\LoggerInterface', $nullRef))
                ->setArgument('$metrics', new Reference(AuditMetrics::class, $nullRef))
                ->addTag('vortos.event_handler')
                ->setPublic(false);
        }

        // Keep vortos:audit:doctor honest now that the export target is wired.
        if ($container->hasDefinition(AuditDoctor::class)) {
            $doctor = $container->getDefinition(AuditDoctor::class);
            $facts  = $doctor->getArgument('$facts');
            if (\is_array($facts)) {
                $facts['has_export_target'] = true;
                $doctor->setArgument('$facts', $facts);
            }
        }
    }

    private function param(ContainerBuilder $container, string $name, string $default): string
    {
        return $container->hasParameter($name) ? (string) $container->getParameter($name) : $default;
    }
}
