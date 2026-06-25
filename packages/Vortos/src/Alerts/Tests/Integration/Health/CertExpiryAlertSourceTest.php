<?php

declare(strict_types=1);

namespace Vortos\Alerts\Tests\Integration\Health;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Vortos\Alerts\Integration\Health\CertExpiryAlertSource;
use Vortos\Alerts\Rule\AlertRule;
use Vortos\Alerts\Rule\AlertRuleEvaluator;
use Vortos\Alerts\Rule\AlertRuleKind;
use Vortos\Alerts\Rule\AlertRuleSet;
use Vortos\Alerts\Rule\Condition\CertExpiryCondition;
use Vortos\Alerts\Severity;
use Vortos\Alerts\Tests\Fixtures\FakeAlertDispatcher;
use Vortos\Health\Probe\Cert\CertExpiryProbe;
use Vortos\Health\Probe\Cert\CertInspectionResult;
use Vortos\Health\Probe\Cert\InMemoryCertInspector;
use Vortos\Health\Probe\HealthProbeRegistry;

final class CertExpiryAlertSourceTest extends TestCase
{
    private function registryWithCertExpiringIn(?int $days, ?string $failureCode = null): HealthProbeRegistry
    {
        $inspector = new InMemoryCertInspector();
        $inspector = $failureCode !== null
            ? $inspector->withResult('example.test', 443, CertInspectionResult::failure($failureCode))
            : $inspector->withResult('example.test', 443, CertInspectionResult::ok($days));

        $probe = new CertExpiryProbe($inspector, 'example.test');

        $container = new class($probe) implements ContainerInterface {
            public function __construct(private CertExpiryProbe $probe) {}

            public function get(string $id): mixed
            {
                return $this->probe;
            }

            public function has(string $id): bool
            {
                return $id === 'cert-expiry';
            }
        };

        return new HealthProbeRegistry($container);
    }

    private function rule(): AlertRule
    {
        return new AlertRule(
            id: 'cert.expiry',
            severity: Severity::Warning,
            kind: AlertRuleKind::CertNearExpiry,
            condition: new CertExpiryCondition(),
            labels: ['probe' => 'cert-expiry'],
        );
    }

    public function testFiresWhenWithinLeadTime(): void
    {
        $dispatcher = new FakeAlertDispatcher();
        $source = new CertExpiryAlertSource(
            $this->registryWithCertExpiringIn(10),
            new AlertRuleSet([$this->rule()]),
            new AlertRuleEvaluator(),
            $dispatcher,
        );

        $source->tick('prod', new DateTimeImmutable());

        self::assertCount(1, $dispatcher->dispatched());
        self::assertSame(Severity::Warning, $dispatcher->dispatched()[0]->severity);
    }

    public function testFiresCriticalWhenWithinCriticalLeadTime(): void
    {
        $dispatcher = new FakeAlertDispatcher();
        $source = new CertExpiryAlertSource(
            $this->registryWithCertExpiringIn(0),
            new AlertRuleSet([$this->rule()]),
            new AlertRuleEvaluator(),
            $dispatcher,
        );

        $source->tick('prod', new DateTimeImmutable());

        self::assertSame(Severity::Critical, $dispatcher->dispatched()[0]->severity);
    }

    public function testDoesNotFireWhenFarFromExpiry(): void
    {
        $dispatcher = new FakeAlertDispatcher();
        $source = new CertExpiryAlertSource(
            $this->registryWithCertExpiringIn(90),
            new AlertRuleSet([$this->rule()]),
            new AlertRuleEvaluator(),
            $dispatcher,
        );

        $source->tick('prod', new DateTimeImmutable());

        self::assertEmpty($dispatcher->dispatched());
    }

    public function testInspectionFailureIsNotTreatedAsAnExpiryMeasurement(): void
    {
        $dispatcher = new FakeAlertDispatcher();
        $source = new CertExpiryAlertSource(
            $this->registryWithCertExpiringIn(null, 'cert_unreachable'),
            new AlertRuleSet([$this->rule()]),
            new AlertRuleEvaluator(),
            $dispatcher,
        );

        $source->tick('prod', new DateTimeImmutable());

        self::assertEmpty($dispatcher->dispatched());
    }
}
