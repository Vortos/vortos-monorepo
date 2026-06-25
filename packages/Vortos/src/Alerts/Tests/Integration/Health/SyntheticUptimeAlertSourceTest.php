<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Integration\Health;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Vortos\Alerts\Integration\Health\InMemoryUptimeUnknownStreakStore;
use Vortos\Alerts\Integration\Health\SyntheticUptimeAlertSource;
use Vortos\Alerts\Rule\AlertRule;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\Condition\NoCondition;
use Vortos\Alerts\Severity;
use Vortos\Alerts\Tests\Fixtures\FakeAlertDispatcher;
use Vortos\Health\Tests\Fixtures\FakeUptimeMonitor;
use Vortos\Health\Uptime\MonitorState;
use Vortos\Health\Uptime\MonitorStatus;
use Vortos\Health\Uptime\UptimeMonitorRegistry;

final class SyntheticUptimeAlertSourceTest extends TestCase
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

    private function rule(): AlertRule
    {
        return new AlertRule(
            id: 'synthetic.login',
            severity: Severity::Critical,
            kind: AlertRuleKind::HealthProbeFailing,
            condition: new NoCondition(),
            labels: ['monitor_id' => 'mon-1'],
        );
    }

    public function testUpMonitorFiresNothing(): void
    {
        $monitor = (new FakeUptimeMonitor())->withStatus('mon-1', new MonitorStatus('mon-1', MonitorState::Up, 5.0, new DateTimeImmutable()));
        $dispatcher = new FakeAlertDispatcher();

        $source = new SyntheticUptimeAlertSource(
            $this->registryFor($monitor),
            'fake',
            new AlertRuleSet([$this->rule()]),
            $dispatcher,
            new InMemoryUptimeUnknownStreakStore(),
        );

        $source->tick('prod', new DateTimeImmutable());

        self::assertEmpty($dispatcher->dispatched());
    }

    public function testDownMonitorFiresCritical(): void
    {
        $monitor = (new FakeUptimeMonitor())->withStatus('mon-1', new MonitorStatus('mon-1', MonitorState::Down, null, new DateTimeImmutable()));
        $dispatcher = new FakeAlertDispatcher();

        $source = new SyntheticUptimeAlertSource(
            $this->registryFor($monitor),
            'fake',
            new AlertRuleSet([$this->rule()]),
            $dispatcher,
            new InMemoryUptimeUnknownStreakStore(),
        );

        $source->tick('prod', new DateTimeImmutable());

        self::assertCount(1, $dispatcher->dispatched());
        self::assertSame(Severity::Critical, $dispatcher->dispatched()[0]->severity);
    }

    public function testDegradedMonitorFiresWarning(): void
    {
        $monitor = (new FakeUptimeMonitor())->withStatus(
            'mon-1',
            new MonitorStatus('mon-1', MonitorState::Degraded, 50.0, new DateTimeImmutable(), ['eu-west']),
        );
        $dispatcher = new FakeAlertDispatcher();

        $source = new SyntheticUptimeAlertSource(
            $this->registryFor($monitor),
            'fake',
            new AlertRuleSet([$this->rule()]),
            $dispatcher,
            new InMemoryUptimeUnknownStreakStore(),
        );

        $source->tick('prod', new DateTimeImmutable());

        self::assertSame(Severity::Warning, $dispatcher->dispatched()[0]->severity);
    }

    public function testUnknownNeverPagesAsDown(): void
    {
        $monitor = new FakeUptimeMonitor(); // never synced -> status() returns Unknown
        $dispatcher = new FakeAlertDispatcher();

        $source = new SyntheticUptimeAlertSource(
            $this->registryFor($monitor),
            'fake',
            new AlertRuleSet([$this->rule()]),
            $dispatcher,
            new InMemoryUptimeUnknownStreakStore(),
        );

        $source->tick('prod', new DateTimeImmutable());
        $source->tick('prod', new DateTimeImmutable());

        self::assertEmpty($dispatcher->dispatched());
    }

    public function testPersistentUnknownRaisesBlindDetectorMetaAlert(): void
    {
        $monitor = new FakeUptimeMonitor();
        $dispatcher = new FakeAlertDispatcher();
        $streaks = new InMemoryUptimeUnknownStreakStore();

        $source = new SyntheticUptimeAlertSource(
            $this->registryFor($monitor),
            'fake',
            new AlertRuleSet([$this->rule()]),
            $dispatcher,
            $streaks,
            blindDetectorThreshold: 3,
        );

        $source->tick('prod', new DateTimeImmutable());
        self::assertEmpty($dispatcher->dispatched(), 'streak 1 must not fire yet');

        $source->tick('prod', new DateTimeImmutable());
        self::assertEmpty($dispatcher->dispatched(), 'streak 2 must not fire yet');

        $source->tick('prod', new DateTimeImmutable());
        self::assertCount(1, $dispatcher->dispatched(), 'streak 3 (the threshold) must fire the blind-detector warning');
        self::assertSame(Severity::Warning, $dispatcher->dispatched()[0]->severity);
    }

    public function testRecoveryAfterUnknownResetsStreakAndStopsMetaAlerts(): void
    {
        $dispatcher = new FakeAlertDispatcher();
        $streaks = new InMemoryUptimeUnknownStreakStore();
        $rules = new AlertRuleSet([$this->rule()]);

        // Tick 1: Unknown (streak 1, below the threshold of 2 — no meta-alert yet).
        $unknownMonitor = new FakeUptimeMonitor();
        (new SyntheticUptimeAlertSource($this->registryFor($unknownMonitor), 'fake', $rules, $dispatcher, $streaks, blindDetectorThreshold: 2))
            ->tick('prod', new DateTimeImmutable());
        self::assertEmpty($dispatcher->dispatched());

        // Tick 2: recovers to Up — the streak must reset, not carry over silently.
        $upMonitor = (new FakeUptimeMonitor())->withStatus('mon-1', new MonitorStatus('mon-1', MonitorState::Up, 5.0, new DateTimeImmutable()));
        (new SyntheticUptimeAlertSource($this->registryFor($upMonitor), 'fake', $rules, $dispatcher, $streaks, blindDetectorThreshold: 2))
            ->tick('prod', new DateTimeImmutable());
        self::assertEmpty($dispatcher->dispatched());

        // Tick 3: Unknown again — if the streak had not reset, this would already hit
        // the threshold of 2 and fire; it must not, since this is only streak 1 again.
        (new SyntheticUptimeAlertSource($this->registryFor($unknownMonitor), 'fake', $rules, $dispatcher, $streaks, blindDetectorThreshold: 2))
            ->tick('prod', new DateTimeImmutable());
        self::assertEmpty($dispatcher->dispatched());
    }

    public function testSkipsRuleWithoutMonitorIdLabel(): void
    {
        $monitor = (new FakeUptimeMonitor())->withStatus('mon-1', new MonitorStatus('mon-1', MonitorState::Down, null, new DateTimeImmutable()));
        $dispatcher = new FakeAlertDispatcher();

        $rule = new AlertRule(
            id: 'no-monitor-id',
            severity: Severity::Critical,
            kind: AlertRuleKind::HealthProbeFailing,
            condition: new NoCondition(),
        );

        $source = new SyntheticUptimeAlertSource(
            $this->registryFor($monitor),
            'fake',
            new AlertRuleSet([$rule]),
            $dispatcher,
            new InMemoryUptimeUnknownStreakStore(),
        );

        $source->tick('prod', new DateTimeImmutable());

        self::assertEmpty($dispatcher->dispatched());
    }
}
