<?php

declare(strict_types=1);

namespace Vortos\Alerts\DependencyInjection\Compiler;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Vortos\Alerts\DependencyInjection\AlertsExtension;
use Vortos\Alerts\AlertDispatcherInterface;
use Vortos\Alerts\Integration\Health\CapacityAlertSource;
use Vortos\Alerts\Integration\Health\CertExpiryAlertSource;
use Vortos\Alerts\Integration\Health\DbalUptimeUnknownStreakStore;
use Vortos\Alerts\Integration\Health\HealthProbeAlertSource;
use Vortos\Alerts\Integration\Health\InMemoryUptimeUnknownStreakStore;
use Vortos\Alerts\Integration\Health\SyntheticUptimeAlertSource;
use Vortos\Alerts\Integration\Health\UptimeUnknownStreakStoreInterface;
use Vortos\Alerts\Rule\AlertRuleEvaluator;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Uptime\UptimeMonitorRegistry;

/**
 * Registers the alert sources that read vortos-health, in a pass rather than in Extension::load().
 *
 * This block used to open with
 *
 *     if (!class_exists(HealthProbeRegistry::class) || !$container->has(HealthProbeRegistry::class))
 *
 * inside AlertsExtension::load(). class_exists() is order-free and answers "is vortos-health
 * INSTALLED?". The has() beside it answers "has vortos-health's extension REGISTERED the registry
 * YET?" — during load() that is a race against extension order, and losing it silently drops every
 * health-derived alert source: probe failures, capacity, cert expiry and synthetic uptime would
 * simply never be evaluated, with nothing to report the gap.
 *
 * That is not hypothetical. The identical construction cost the alert audit ledger (FB-36/FB-37)
 * and, in HealthExtension, failed a production deploy by reporting a wired detector as missing
 * (FB-38). A compiler pass runs after every extension has loaded, so has() is an answer.
 *
 * The env-driven arguments are declared references rather than inline $_ENV reads for the same
 * reason the audit recorder's signing key is: the container compiles in a clean environment, so an
 * inline read resolves against the build host, not the runtime host.
 */
final class AlertsHealthIntegrationPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->has(HealthProbeRegistry::class)) {
            return; // vortos-health genuinely absent — health-derived sources cannot exist.
        }

        if ($container->hasDefinition(HealthProbeAlertSource::class)) {
            return; // already registered.
        }

        foreach ([HealthProbeAlertSource::class, CapacityAlertSource::class, CertExpiryAlertSource::class] as $source) {
            $container->register($source, $source)
                ->setArgument('$probes', new Reference(HealthProbeRegistry::class))
                ->setArgument('$rules', new Reference(AlertRuleSet::class))
                ->setArgument('$evaluator', new Reference(AlertRuleEvaluator::class))
                ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
                ->addTag(AlertsExtension::SOURCE_TAG)
                ->setPublic(true);
        }

        if (!$container->has(UptimeMonitorRegistry::class)) {
            return;
        }

        $prefix = $container->hasParameter('vortos.db.framework_table_prefix')
            ? (string) $container->getParameter('vortos.db.framework_table_prefix')
            : 'vortos_';

        if ($container->has(Connection::class)) {
            $container->register(DbalUptimeUnknownStreakStore::class, DbalUptimeUnknownStreakStore::class)
                ->setArgument('$connection', new Reference(Connection::class))
                ->setArgument('$table', $prefix . 'alerts_uptime_streaks')
                ->setPublic(false);
            $container->setAlias(UptimeUnknownStreakStoreInterface::class, DbalUptimeUnknownStreakStore::class)
                ->setPublic(false);
        } else {
            $container->register(InMemoryUptimeUnknownStreakStore::class, InMemoryUptimeUnknownStreakStore::class)
                ->setPublic(false);
            $container->setAlias(UptimeUnknownStreakStoreInterface::class, InMemoryUptimeUnknownStreakStore::class)
                ->setPublic(false);
        }

        $container->setParameter('env(ALERTS_UPTIME_MONITOR_DRIVER)', 'null');
        $container->setParameter('env(ALERTS_UPTIME_BLIND_DETECTOR_THRESHOLD)', '3');

        $container->register(SyntheticUptimeAlertSource::class, SyntheticUptimeAlertSource::class)
            ->setArgument('$monitors', new Reference(UptimeMonitorRegistry::class))
            ->setArgument('$monitorDriverKey', '%env(string:ALERTS_UPTIME_MONITOR_DRIVER)%')
            ->setArgument('$rules', new Reference(AlertRuleSet::class))
            ->setArgument('$dispatcher', new Reference(AlertDispatcherInterface::class))
            ->setArgument('$streaks', new Reference(UptimeUnknownStreakStoreInterface::class))
            ->setArgument('$blindDetectorThreshold', '%env(int:ALERTS_UPTIME_BLIND_DETECTOR_THRESHOLD)%')
            ->addTag(AlertsExtension::SOURCE_TAG)
            ->setPublic(true);
    }
}
