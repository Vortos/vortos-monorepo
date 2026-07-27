<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Unit;

use Doctrine\DBAL\Connection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Vortos\Alerts\DependencyInjection\AlertsExtension;
use Vortos\Alerts\DependencyInjection\Compiler\AlertsHealthIntegrationPass;
use Vortos\Alerts\Integration\Health\CapacityAlertSource;
use Vortos\Alerts\Integration\Health\CertExpiryAlertSource;
use Vortos\Alerts\Integration\Health\DbalUptimeUnknownStreakStore;
use Vortos\Alerts\Integration\Health\HealthProbeAlertSource;
use Vortos\Alerts\Integration\Health\InMemoryUptimeUnknownStreakStore;
use Vortos\Alerts\Integration\Health\SyntheticUptimeAlertSource;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Uptime\UptimeMonitorRegistry;

/**
 * The health-derived alert sources used to be registered in AlertsExtension::load() behind
 * `class_exists(HealthProbeRegistry) && $container->has(HealthProbeRegistry)`. The has() is a race
 * against extension load order; losing it dropped probe-failure, capacity, cert-expiry and
 * synthetic-uptime alerting entirely, with no error and nothing to report the gap.
 *
 * These tests exercise the pass against a container where vortos-health is present, absent, and
 * present-without-uptime, which is what load() could never reliably observe.
 */
final class AlertsHealthIntegrationPassTest extends TestCase
{
    private function containerWith(bool $health, bool $uptime, bool $connection): ContainerBuilder
    {
        $c = new ContainerBuilder();
        if ($health) {
            $c->register(HealthProbeRegistry::class, HealthProbeRegistry::class)->setSynthetic(true);
        }
        if ($uptime) {
            $c->register(UptimeMonitorRegistry::class, UptimeMonitorRegistry::class)->setSynthetic(true);
        }
        if ($connection) {
            $c->register(Connection::class, Connection::class)->setSynthetic(true);
        }

        return $c;
    }

    public function test_registers_the_probe_derived_sources_when_health_is_present(): void
    {
        $c = $this->containerWith(health: true, uptime: false, connection: false);
        (new AlertsHealthIntegrationPass())->process($c);

        foreach ([HealthProbeAlertSource::class, CapacityAlertSource::class, CertExpiryAlertSource::class] as $id) {
            self::assertTrue($c->hasDefinition($id), $id . ' should be registered when vortos-health is present.');
            self::assertArrayHasKey(
                AlertsExtension::SOURCE_TAG,
                $c->getDefinition($id)->getTags(),
                $id . ' must carry the alert source tag or the ticker never evaluates it.',
            );
        }
    }

    public function test_registers_nothing_when_health_is_absent(): void
    {
        $c = $this->containerWith(health: false, uptime: false, connection: false);
        (new AlertsHealthIntegrationPass())->process($c);

        self::assertFalse($c->hasDefinition(HealthProbeAlertSource::class));
    }

    public function test_uptime_source_needs_the_uptime_registry(): void
    {
        $c = $this->containerWith(health: true, uptime: false, connection: true);
        (new AlertsHealthIntegrationPass())->process($c);

        self::assertFalse(
            $c->hasDefinition(SyntheticUptimeAlertSource::class),
            'Synthetic uptime alerting cannot exist without the uptime monitor registry.',
        );
    }

    public function test_uptime_streaks_are_durable_when_a_connection_exists(): void
    {
        $c = $this->containerWith(health: true, uptime: true, connection: true);
        (new AlertsHealthIntegrationPass())->process($c);

        self::assertTrue($c->hasDefinition(SyntheticUptimeAlertSource::class));
        self::assertTrue($c->hasDefinition(DbalUptimeUnknownStreakStore::class));
        self::assertFalse($c->hasDefinition(InMemoryUptimeUnknownStreakStore::class));
    }

    public function test_uptime_streaks_fall_back_to_memory_without_a_connection(): void
    {
        $c = $this->containerWith(health: true, uptime: true, connection: false);
        (new AlertsHealthIntegrationPass())->process($c);

        self::assertTrue($c->hasDefinition(InMemoryUptimeUnknownStreakStore::class));
        self::assertFalse($c->hasDefinition(DbalUptimeUnknownStreakStore::class));
    }

    public function test_env_driven_arguments_are_declared_references_not_inline_reads(): void
    {
        $c = $this->containerWith(health: true, uptime: true, connection: true);
        (new AlertsHealthIntegrationPass())->process($c);

        $args = $c->getDefinition(SyntheticUptimeAlertSource::class)->getArguments();

        self::assertSame('%env(string:ALERTS_UPTIME_MONITOR_DRIVER)%', $args['$monitorDriverKey']);
        self::assertSame('%env(int:ALERTS_UPTIME_BLIND_DETECTOR_THRESHOLD)%', $args['$blindDetectorThreshold']);
    }

    public function test_is_idempotent(): void
    {
        $c = $this->containerWith(health: true, uptime: true, connection: true);
        $pass = new AlertsHealthIntegrationPass();
        $pass->process($c);
        $pass->process($c);

        self::assertTrue($c->hasDefinition(HealthProbeAlertSource::class));
    }
}
