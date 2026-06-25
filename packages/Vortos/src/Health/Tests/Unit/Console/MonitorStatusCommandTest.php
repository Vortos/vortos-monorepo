<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Console;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Vortos\Health\Console\MonitorStatusCommand;
use Vortos\Health\Tests\Fixtures\FakeUptimeMonitor;
use Vortos\Health\Uptime\MonitorState;
use Vortos\Health\Uptime\MonitorStatus;
use Vortos\Health\Uptime\UptimeMonitorRegistry;

final class MonitorStatusCommandTest extends TestCase
{
    private function registryFor(FakeUptimeMonitor $monitor): UptimeMonitorRegistry
    {
        $container = new class($monitor) implements ContainerInterface {
            public function __construct(private FakeUptimeMonitor $monitor) {}

            public function get(string $id): mixed
            {
                return $this->monitor;
            }

            public function has(string $id): bool
            {
                return $id === 'fake';
            }
        };

        return new UptimeMonitorRegistry($container);
    }

    public function testUpMonitorExitsZero(): void
    {
        $monitor = (new FakeUptimeMonitor())->withStatus('mon-1', new MonitorStatus('mon-1', MonitorState::Up, 12.0, new DateTimeImmutable()));

        $tester = new CommandTester(new MonitorStatusCommand($this->registryFor($monitor)));
        $tester->execute(['monitor-id' => 'mon-1', '--driver' => 'fake']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('up', $tester->getDisplay());
    }

    public function testDownMonitorExitsNonZero(): void
    {
        $monitor = (new FakeUptimeMonitor())->withStatus('mon-1', new MonitorStatus('mon-1', MonitorState::Down, null, new DateTimeImmutable()));

        $tester = new CommandTester(new MonitorStatusCommand($this->registryFor($monitor)));
        $tester->execute(['monitor-id' => 'mon-1', '--driver' => 'fake']);

        self::assertSame(1, $tester->getStatusCode());
    }

    public function testUnknownMonitorExitsZeroNotAsDown(): void
    {
        $monitor = new FakeUptimeMonitor();

        $tester = new CommandTester(new MonitorStatusCommand($this->registryFor($monitor)));
        $tester->execute(['monitor-id' => 'never-synced', '--driver' => 'fake']);

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('unknown', $tester->getDisplay());
    }

    public function testJsonOutputIsValidJson(): void
    {
        $monitor = (new FakeUptimeMonitor())->withStatus(
            'mon-1',
            new MonitorStatus('mon-1', MonitorState::Degraded, 5.0, new DateTimeImmutable(), ['eu-west'], 'inc-1'),
        );

        $tester = new CommandTester(new MonitorStatusCommand($this->registryFor($monitor)));
        $tester->execute(['monitor-id' => 'mon-1', '--driver' => 'fake', '--json' => true]);

        $decoded = json_decode($tester->getDisplay(), true);
        self::assertSame('degraded', $decoded['state']);
        self::assertSame(['eu-west'], $decoded['failing_regions']);
        self::assertSame('inc-1', $decoded['incident_id']);
    }
}
