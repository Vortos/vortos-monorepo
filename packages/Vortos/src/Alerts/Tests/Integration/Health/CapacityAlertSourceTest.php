<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Integration\Health;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Vortos\Alerts\Integration\Health\CapacityAlertSource;
use Vortos\Alerts\Rule\AlertRule;
use Vortos\Alerts\Rule\AlertRuleEvaluator;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\Condition\ResourceCondition;
use Vortos\Alerts\Severity;
use Vortos\Alerts\Tests\Fixtures\FakeAlertDispatcher;
use Vortos\Health\Probe\Capacity\CapacityReader\InMemoryCapacityReader;
use Vortos\Health\Probe\Capacity\DiskCapacityProbe;
use Vortos\Health\Probe\HealthProbeRegistry;

final class CapacityAlertSourceTest extends TestCase
{
    private function registryWithDiskAt(float $usedPct): HealthProbeRegistry
    {
        $probe = new DiskCapacityProbe(new InMemoryCapacityReader(diskUsedPct: $usedPct));

        $container = new class($probe) implements ContainerInterface {
            public function __construct(private DiskCapacityProbe $probe) {}

            public function get(string $id): mixed
            {
                return $this->probe;
            }

            public function has(string $id): bool
            {
                return $id === 'disk-capacity';
            }
        };

        return new HealthProbeRegistry($container);
    }

    private function rule(): AlertRule
    {
        return new AlertRule(
            id: 'disk.exhaustion',
            severity: Severity::Critical,
            kind: AlertRuleKind::ResourceExhaustion,
            condition: new ResourceCondition(),
            labels: ['probe' => 'disk-capacity'],
        );
    }

    public function testFiresWhenDiskCrossesCriticalThreshold(): void
    {
        $dispatcher = new FakeAlertDispatcher();
        $source = new CapacityAlertSource(
            $this->registryWithDiskAt(96.0),
            new AlertRuleSet([$this->rule()]),
            new AlertRuleEvaluator(),
            $dispatcher,
        );

        $source->tick('prod', new DateTimeImmutable());

        self::assertCount(1, $dispatcher->dispatched());
        self::assertSame(Severity::Critical, $dispatcher->dispatched()[0]->severity);
    }

    public function testDoesNotFireWhenBelowThreshold(): void
    {
        $dispatcher = new FakeAlertDispatcher();
        $source = new CapacityAlertSource(
            $this->registryWithDiskAt(10.0),
            new AlertRuleSet([$this->rule()]),
            new AlertRuleEvaluator(),
            $dispatcher,
        );

        $source->tick('prod', new DateTimeImmutable());

        self::assertEmpty($dispatcher->dispatched());
    }

    public function testSkipsRuleForUnknownProbeName(): void
    {
        $dispatcher = new FakeAlertDispatcher();
        $rule = new AlertRule(
            id: 'disk.exhaustion',
            severity: Severity::Critical,
            kind: AlertRuleKind::ResourceExhaustion,
            condition: new ResourceCondition(),
            labels: ['probe' => 'does-not-exist'],
        );

        $source = new CapacityAlertSource(
            $this->registryWithDiskAt(99.0),
            new AlertRuleSet([$rule]),
            new AlertRuleEvaluator(),
            $dispatcher,
        );

        $source->tick('prod', new DateTimeImmutable());

        self::assertEmpty($dispatcher->dispatched());
    }

    public function testIgnoresRulesOfOtherKinds(): void
    {
        $dispatcher = new FakeAlertDispatcher();
        $rule = new AlertRule(
            id: 'unrelated',
            severity: Severity::Critical,
            kind: AlertRuleKind::CertNearExpiry,
            condition: new \Vortos\Alerts\Rule\Condition\CertExpiryCondition(),
            labels: ['probe' => 'disk-capacity'],
        );

        $source = new CapacityAlertSource(
            $this->registryWithDiskAt(99.0),
            new AlertRuleSet([$rule]),
            new AlertRuleEvaluator(),
            $dispatcher,
        );

        $source->tick('prod', new DateTimeImmutable());

        self::assertEmpty($dispatcher->dispatched());
    }
}
