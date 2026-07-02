<?php

declare(strict_types=1);

namespace Vortos\Health\DependencyInjection;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Foundation\Health\HealthDetailPolicy;
use Vortos\Health\Aggregator\HealthAggregator;
use Vortos\Health\Aggregator\HealthBudget;
use Vortos\Health\Console\MonitorStatusCommand;
use Vortos\Health\Console\MonitorSyncCommand;
use Vortos\Health\Console\MonitorTickCommand;
use Vortos\Health\DependencyInjection\Compiler\CollectHealthProbesPass;
use Vortos\Health\DependencyInjection\Compiler\CollectUptimeMonitorsPass;
use Vortos\Health\Http\HealthController;
use Vortos\Health\Monitor\HeartbeatPolicy;
use Vortos\Health\Monitor\InMemorySyncRecordStore;
use Vortos\Health\Monitor\DbalSyncRecordStore;
use Vortos\Health\Monitor\MonitorDescriptorHasher;
use Vortos\Health\Monitor\MonitorTick;
use Vortos\Health\Monitor\SyncRecordStoreInterface;
use Vortos\Health\Probe\Capacity\CapacityReader\CapacityReaderInterface;
use Vortos\Health\Probe\Capacity\CapacityReader\ProcCapacityReader;
use Vortos\Health\Probe\Capacity\CpuLoadProbe;
use Vortos\Health\Probe\Capacity\DiskCapacityProbe;
use Vortos\Health\Probe\Capacity\MemoryCapacityProbe;
use Vortos\Health\Probe\Cert\CertExpiryProbe;
use Vortos\Health\Probe\Cert\CertExpiryThresholds;
use Vortos\Health\Probe\Cert\CertInspectorInterface;
use Vortos\Health\Probe\Cert\StreamCertInspector;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Probe\ProcessLivenessProbe;
use Vortos\Health\Startup\StartupGate;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackClient;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackJourneyRenderer;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackTransportInterface;
use Vortos\Health\Uptime\Driver\BetterStack\BetterStackUptimeMonitor;
use Vortos\Health\Uptime\Driver\BetterStack\CurlBetterStackTransport;
use Vortos\Health\Uptime\Driver\Null\NullUptimeMonitor;
use Vortos\Health\Uptime\MonitorDescriptorSet;
use Vortos\Health\Uptime\UptimeMonitorInterface;
use Vortos\Health\Uptime\UptimeMonitorRegistry;

final class HealthExtension extends Extension
{
    public function getAlias(): string
    {
        return 'vortos_health';
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $this->registerProbeSeam($container);
        $this->registerAggregator($container);
        $this->registerCapacityProbes($container);
        $this->registerCertProbe($container);
        $this->registerUptimeSeam($container);
        $this->registerMonitorOrchestration($container);
        $this->registerCommands($container);
        $this->registerDeployIntegration($container);
    }

    private function registerProbeSeam(ContainerBuilder $container): void
    {
        $container->register(CollectHealthProbesPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(HealthProbeRegistry::class, HealthProbeRegistry::class)
            ->setArgument('$drivers', new Reference(CollectHealthProbesPass::LOCATOR_ID))
            ->setPublic(false);

        $container->registerForAutoconfiguration(HealthProbeInterface::class)
            ->addTag(CollectHealthProbesPass::TAG);

        $container->register(ProcessLivenessProbe::class, ProcessLivenessProbe::class)
            ->addTag(CollectHealthProbesPass::TAG, ['key' => 'process-liveness'])
            ->setPublic(false);
    }

    private function registerAggregator(ContainerBuilder $container): void
    {
        $container->register(HealthBudget::class, HealthBudget::class)
            ->setArgument('$perProbeDeadlineMs', (int) ($_ENV['HEALTH_PROBE_DEADLINE_MS'] ?? 3000))
            ->setArgument('$overallBudgetMs', (int) ($_ENV['HEALTH_OVERALL_BUDGET_MS'] ?? 10000))
            ->setArgument('$readyCacheTtlMs', (int) ($_ENV['HEALTH_READY_CACHE_TTL_MS'] ?? 1000))
            ->setPublic(false);

        $container->register(StartupGate::class, StartupGate::class)
            ->setShared(true)
            ->setPublic(false);

        $container->register(HealthAggregator::class, HealthAggregator::class)
            ->setArgument('$registry', new Reference(HealthProbeRegistry::class))
            ->setArgument('$budget', new Reference(HealthBudget::class))
            ->setArgument('$startupGate', new Reference(StartupGate::class))
            ->setPublic(false);

        $container->register(HealthController::class, HealthController::class)
            ->setArgument('$aggregator', new Reference(HealthAggregator::class))
            ->setArgument('$detailPolicy', new Reference(HealthDetailPolicy::class))
            ->addTag('vortos.api.controller')
            ->setPublic(true);
    }

    private function registerCapacityProbes(ContainerBuilder $container): void
    {
        $container->register(ProcCapacityReader::class, ProcCapacityReader::class)->setPublic(false);
        $container->setAlias(CapacityReaderInterface::class, ProcCapacityReader::class)->setPublic(false);

        $warnPct = (float) ($_ENV['HEALTH_CAPACITY_WARN_PCT'] ?? 85.0);
        $criticalPct = (float) ($_ENV['HEALTH_CAPACITY_CRITICAL_PCT'] ?? 95.0);

        $container->register(DiskCapacityProbe::class, DiskCapacityProbe::class)
            ->setArgument('$reader', new Reference(CapacityReaderInterface::class))
            ->setArgument('$path', (string) ($_ENV['HEALTH_DISK_PATH'] ?? '/'))
            ->setArgument('$warnPct', $warnPct)
            ->setArgument('$criticalPct', $criticalPct)
            ->addTag(CollectHealthProbesPass::TAG, ['key' => 'disk-capacity'])
            ->setPublic(false);

        $container->register(MemoryCapacityProbe::class, MemoryCapacityProbe::class)
            ->setArgument('$reader', new Reference(CapacityReaderInterface::class))
            ->setArgument('$warnPct', $warnPct)
            ->setArgument('$criticalPct', $criticalPct)
            ->addTag(CollectHealthProbesPass::TAG, ['key' => 'memory-capacity'])
            ->setPublic(false);

        $container->register(CpuLoadProbe::class, CpuLoadProbe::class)
            ->setArgument('$reader', new Reference(CapacityReaderInterface::class))
            ->setArgument('$warnPct', $warnPct)
            ->setArgument('$criticalPct', $criticalPct)
            ->addTag(CollectHealthProbesPass::TAG, ['key' => 'cpu-load'])
            ->setPublic(false);
    }

    private function registerCertProbe(ContainerBuilder $container): void
    {
        $host = (string) ($_ENV['HEALTH_CERT_HOST'] ?? '');
        if ($host === '') {
            // No target configured — this is an opt-in probe (it inspects an external
            // TLS endpoint, there is no sane default host to inspect).
            return;
        }

        $container->register(StreamCertInspector::class, StreamCertInspector::class)->setPublic(false);
        $container->setAlias(CertInspectorInterface::class, StreamCertInspector::class)->setPublic(false);

        $container->register(CertExpiryThresholds::class, CertExpiryThresholds::class)
            ->setArgument('$warnDays', (int) ($_ENV['HEALTH_CERT_WARN_DAYS'] ?? 14))
            ->setArgument('$secondWarnDays', (int) ($_ENV['HEALTH_CERT_SECOND_WARN_DAYS'] ?? 7))
            ->setArgument('$criticalDays', (int) ($_ENV['HEALTH_CERT_CRITICAL_DAYS'] ?? 1))
            ->setPublic(false);

        $container->register(CertExpiryProbe::class, CertExpiryProbe::class)
            ->setArgument('$inspector', new Reference(CertInspectorInterface::class))
            ->setArgument('$host', $host)
            ->setArgument('$port', (int) ($_ENV['HEALTH_CERT_PORT'] ?? 443))
            ->setArgument('$thresholds', new Reference(CertExpiryThresholds::class))
            ->addTag(CollectHealthProbesPass::TAG, ['key' => 'cert-expiry'])
            ->setPublic(false);
    }

    private function registerUptimeSeam(ContainerBuilder $container): void
    {
        $container->register(CollectUptimeMonitorsPass::LOCATOR_ID)
            ->addTag('container.service_locator')
            ->setArgument(0, []);

        $container->register(UptimeMonitorRegistry::class, UptimeMonitorRegistry::class)
            ->setArgument('$drivers', new Reference(CollectUptimeMonitorsPass::LOCATOR_ID))
            ->setPublic(true); // app config selects the active driver by key

        $container->registerForAutoconfiguration(UptimeMonitorInterface::class)
            ->addTag(CollectUptimeMonitorsPass::TAG);

        $container->register(NullUptimeMonitor::class, NullUptimeMonitor::class)
            ->addTag(CollectUptimeMonitorsPass::TAG, ['key' => 'null'])
            ->setPublic(false);

        // No heavy SDK dependency (plain curl, §14.2) -> in-core, unconditionally
        // available; selection happens by key, not by presence-guard.
        $container->register(BetterStackTransportInterface::class, CurlBetterStackTransport::class)->setPublic(false);

        $container->register(BetterStackClient::class, BetterStackClient::class)
            ->setArgument('$transport', new Reference(BetterStackTransportInterface::class))
            ->setArgument('$tokenEnvVar', (string) ($_ENV['UPTIME_MONITOR_BETTERSTACK_TOKEN_VAR'] ?? 'UPTIME_MONITOR_BETTERSTACK_TOKEN'))
            ->setPublic(false);

        $container->register(BetterStackJourneyRenderer::class, BetterStackJourneyRenderer::class)->setPublic(false);

        $container->register(BetterStackUptimeMonitor::class, BetterStackUptimeMonitor::class)
            ->setArgument('$client', new Reference(BetterStackClient::class))
            ->setArgument('$renderer', new Reference(BetterStackJourneyRenderer::class))
            ->addTag(CollectUptimeMonitorsPass::TAG, ['key' => 'betterstack'])
            ->setPublic(false);
    }

    private function registerMonitorOrchestration(ContainerBuilder $container): void
    {
        $container->register(MonitorDescriptorSet::class, MonitorDescriptorSet::class)
            ->setArgument('$descriptors', [])
            ->setPublic(true); // app config overrides this with its declared journeys

        $container->register(MonitorDescriptorHasher::class, MonitorDescriptorHasher::class)->setPublic(false);
        $container->register(HeartbeatPolicy::class, HeartbeatPolicy::class)->setPublic(false);

        if ($container->has(Connection::class)) {
            $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
                ? $container->getParameter('vortos.db.framework_table_prefix')
                : 'vortos_';

            $container->register(DbalSyncRecordStore::class, DbalSyncRecordStore::class)
                ->setArgument('$connection', new Reference(Connection::class))
                ->setArgument('$table', $prefix . 'uptime_sync')
                ->setPublic(false);
            $container->setAlias(SyncRecordStoreInterface::class, DbalSyncRecordStore::class)->setPublic(false);
        } else {
            $container->register(InMemorySyncRecordStore::class, InMemorySyncRecordStore::class)->setPublic(false);
            $container->setAlias(SyncRecordStoreInterface::class, InMemorySyncRecordStore::class)->setPublic(false);
        }

        $uptimeDriverKey = (string) ($_ENV['UPTIME_MONITOR_DRIVER'] ?? 'null');

        $container->register('vortos.health.uptime.selected_monitor', UptimeMonitorInterface::class)
            ->setFactory([new Reference(UptimeMonitorRegistry::class), 'monitor'])
            ->setArguments([$uptimeDriverKey])
            ->setPublic(false);

        // Optional cross-package collaborator: use the observability heartbeat emitter if it is
        // present, null otherwise. NULL_ON_INVALID_REFERENCE resolves absence at compile end
        // without an order-dependent has() in load(). ::class produces the string literal even
        // when vortos-observability is not installed, so this is safe either way.
        $container->register(MonitorTick::class, MonitorTick::class)
            ->setArgument('$monitor', new Reference('vortos.health.uptime.selected_monitor'))
            ->setArgument('$heartbeatPolicy', new Reference(HeartbeatPolicy::class))
            ->setArgument('$heartbeatMonitorKey', (string) ($_ENV['HEALTH_MONITOR_HEARTBEAT_KEY'] ?? 'health-monitor-tick'))
            ->setArgument('$heartbeatEmitter', new Reference(
                \Vortos\Observability\Heartbeat\HeartbeatEmitterInterface::class,
                ContainerInterface::NULL_ON_INVALID_REFERENCE,
            ))
            ->setPublic(false);
    }

    private function registerCommands(ContainerBuilder $container): void
    {
        $defaultMonitorIds = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) ($_ENV['UPTIME_MONITOR_DEFAULT_IDS'] ?? '')),
        )));

        $container->register(MonitorTickCommand::class, MonitorTickCommand::class)
            ->setArgument('$tick', new Reference(MonitorTick::class))
            ->setArgument('$probes', new Reference(HealthProbeRegistry::class))
            ->setArgument('$defaultMonitorIds', $defaultMonitorIds)
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MonitorSyncCommand::class, MonitorSyncCommand::class)
            ->setArgument('$descriptors', new Reference(MonitorDescriptorSet::class))
            ->setArgument('$monitors', new Reference(UptimeMonitorRegistry::class))
            ->setArgument('$store', new Reference(SyncRecordStoreInterface::class))
            ->setArgument('$hasher', new Reference(MonitorDescriptorHasher::class))
            ->setPublic(true)
            ->addTag('console.command');

        $container->register(MonitorStatusCommand::class, MonitorStatusCommand::class)
            ->setArgument('$monitors', new Reference(UptimeMonitorRegistry::class))
            ->setPublic(true)
            ->addTag('console.command');
    }

    private function registerDeployIntegration(ContainerBuilder $container): void
    {
        if (!interface_exists(\Vortos\Deploy\Preflight\PreflightCheckInterface::class)) {
            return;
        }

        $heartbeatConfigured = class_exists(\Vortos\Observability\Heartbeat\HeartbeatEmitterInterface::class)
            && $container->has(\Vortos\Observability\Heartbeat\HeartbeatEmitterInterface::class);

        $container->register(\Vortos\Health\Preflight\DetectorIndependenceDoctorCheck::class, \Vortos\Health\Preflight\DetectorIndependenceDoctorCheck::class)
            ->setArgument('$probes', new Reference(HealthProbeRegistry::class))
            ->setArgument('$uptimeMonitors', new Reference(UptimeMonitorRegistry::class))
            ->setArgument('$configuredUptimeDriverKey', (string) ($_ENV['UPTIME_MONITOR_DRIVER'] ?? 'null'))
            ->setArgument('$heartbeatConfigured', $heartbeatConfigured)
            ->setPublic(false);

        $container->register(\Vortos\Health\Preflight\LivenessIndependenceDoctorCheck::class, \Vortos\Health\Preflight\LivenessIndependenceDoctorCheck::class)
            ->setArgument('$probes', new Reference(HealthProbeRegistry::class))
            ->setPublic(false);
    }
}
