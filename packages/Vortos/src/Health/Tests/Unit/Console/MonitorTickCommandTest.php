<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Console;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Vortos\Health\Console\MonitorTickCommand;
use Vortos\Health\Monitor\HeartbeatPolicy;
use Vortos\Health\Monitor\MonitorTick;
use Vortos\Health\Probe\HealthProbeInterface;
use Vortos\Health\Probe\HealthProbeRegistry;
use Vortos\Health\Tests\Fixtures\FakeUptimeMonitor;
use Vortos\Health\Tests\Fixtures\StubProbe;

final class MonitorTickCommandTest extends TestCase
{
    /** @param array<string, HealthProbeInterface> $probes */
    private function registry(array $probes): HealthProbeRegistry
    {
        $container = new class($probes) implements ContainerInterface {
            public function __construct(private array $probes) {}

            public function get(string $id): mixed
            {
                return $this->probes[$id];
            }

            public function has(string $id): bool
            {
                return isset($this->probes[$id]);
            }
        };

        return new HealthProbeRegistry($container);
    }

    public function testExitsZeroWhenHealthy(): void
    {
        $tick = new MonitorTick(new FakeUptimeMonitor(), new HeartbeatPolicy());
        $registry = $this->registry(['disk-capacity' => StubProbe::readiness('disk-capacity')]);

        $tester = new CommandTester(new MonitorTickCommand($tick, $registry));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('OK', $tester->getDisplay());
    }

    public function testExitsNonZeroWhenAProbeFails(): void
    {
        $tick = new MonitorTick(new FakeUptimeMonitor(), new HeartbeatPolicy());
        $failing = StubProbe::readiness('disk-capacity')
            ->withResult(\Vortos\Health\Probe\ProbeResult::fail('disk-capacity', \Vortos\Health\Probe\ProbeKind::Readiness, 1.0, 'capacity_critical'));
        $registry = $this->registry(['disk-capacity' => $failing]);

        $tester = new CommandTester(new MonitorTickCommand($tick, $registry));
        $tester->execute([]);

        self::assertSame(1, $tester->getStatusCode());
        self::assertStringContainsString('UNHEALTHY', $tester->getDisplay());
    }

    public function testJsonOutputIsValidJson(): void
    {
        $tick = new MonitorTick(new FakeUptimeMonitor(), new HeartbeatPolicy());
        $registry = $this->registry(['disk-capacity' => StubProbe::readiness('disk-capacity')]);

        $tester = new CommandTester(new MonitorTickCommand($tick, $registry));
        $tester->execute(['--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertIsArray($decoded);
        self::assertTrue($decoded['healthy']);
    }

    public function testExplicitProbeOptionOverridesDefaults(): void
    {
        $tick = new MonitorTick(new FakeUptimeMonitor(), new HeartbeatPolicy());
        $registry = $this->registry([
            'disk-capacity' => StubProbe::readiness('disk-capacity'),
            'custom-probe' => StubProbe::readiness('custom-probe'),
        ]);

        $tester = new CommandTester(new MonitorTickCommand($tick, $registry));
        $tester->execute(['--probe' => ['custom-probe']]);

        self::assertStringContainsString('custom-probe', $tester->getDisplay());
        self::assertStringNotContainsString('disk-capacity', $tester->getDisplay());
    }

    public function testDefaultSetAutoDiscoversMonitoringProbes(): void
    {
        // GAP-F: the default probe set auto-includes every Monitoring-kind probe (e.g. cert-expiry)
        // plus registered capacity probes — no hardcoded name list. A readiness probe is NOT sampled.
        $locator = new ServiceLocator([
            'cert-expiry' => static fn () => StubProbe::monitoring('cert-expiry'),
            'disk-capacity' => static fn () => StubProbe::readiness('disk-capacity'),
            'db' => static fn () => StubProbe::readiness('db'),
        ]);
        $registry = new HealthProbeRegistry($locator);

        $tick = new MonitorTick(new FakeUptimeMonitor(), new HeartbeatPolicy());
        $tester = new CommandTester(new MonitorTickCommand($tick, $registry));
        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('cert-expiry', $display);   // monitoring-kind, auto-discovered
        self::assertStringContainsString('disk-capacity', $display); // capacity, still sampled
        self::assertStringNotContainsString('probe db', $display);   // readiness, NOT a monitor sample
    }

    public function testMissingDefaultProbesAreSkippedSilently(): void
    {
        $tick = new MonitorTick(new FakeUptimeMonitor(), new HeartbeatPolicy());
        $registry = $this->registry([]);

        $tester = new CommandTester(new MonitorTickCommand($tick, $registry));
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode());
    }
}
