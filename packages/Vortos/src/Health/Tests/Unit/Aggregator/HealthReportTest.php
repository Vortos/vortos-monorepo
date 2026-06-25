<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Aggregator;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Aggregator\HealthReport;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\Health\Probe\ProbeStatus;

final class HealthReportTest extends TestCase
{
    public function testPassReportReturns200(): void
    {
        $report = $this->makeReport(ProbeStatus::Pass);

        self::assertTrue($report->isHealthy());
        self::assertSame(200, $report->httpStatusCode());
    }

    public function testWarnReportReturns200(): void
    {
        $report = $this->makeReport(ProbeStatus::Warn);

        self::assertTrue($report->isHealthy());
        self::assertSame(200, $report->httpStatusCode());
    }

    public function testFailReportReturns503(): void
    {
        $report = $this->makeReport(ProbeStatus::Fail);

        self::assertFalse($report->isHealthy());
        self::assertSame(503, $report->httpStatusCode());
    }

    public function testPublicArrayShapeIsStable(): void
    {
        $ts = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $report = new HealthReport(ProbeStatus::Pass, 'ready', [], null, 0.0, $ts);

        $expected = [
            'status' => 'pass',
            'mode' => 'ready',
            'timestamp' => '2026-01-01T00:00:00+00:00',
        ];

        self::assertSame($expected, $report->toPublicArray());
    }

    public function testDetailedArrayShapeIsStable(): void
    {
        $ts = new \DateTimeImmutable('2026-01-01T00:00:00+00:00');
        $result = ProbeResult::pass('db', ProbeKind::Readiness, 1.23);
        $report = new HealthReport(ProbeStatus::Pass, 'ready', [$result], $result, 1.23, $ts);

        $detailed = $report->toDetailedArray();

        self::assertSame('pass', $detailed['status']);
        self::assertSame('ready', $detailed['mode']);
        self::assertSame('2026-01-01T00:00:00+00:00', $detailed['timestamp']);
        self::assertSame(1.23, $detailed['total_latency_ms']);
        self::assertSame('db', $detailed['slowest']);
        self::assertArrayHasKey('checks', $detailed);
        self::assertArrayHasKey('db', $detailed['checks']);
    }

    public function testDetailedArrayOmitsErrorsWhenNotIncluded(): void
    {
        $ts = new \DateTimeImmutable();
        $result = ProbeResult::fail('db', ProbeKind::Readiness, 1.0, 'down', ['hint' => 'dead']);
        $report = new HealthReport(ProbeStatus::Fail, 'ready', [$result], $result, 1.0, $ts);

        $detailed = $report->toDetailedArray(includeErrors: false);

        self::assertArrayNotHasKey('error_code', $detailed['checks']['db']);
        self::assertArrayNotHasKey('detail', $detailed['checks']['db']);
    }

    public function testDetailedArrayWithNoSlowest(): void
    {
        $ts = new \DateTimeImmutable();
        $report = new HealthReport(ProbeStatus::Pass, 'live', [], null, 0.0, $ts);

        $detailed = $report->toDetailedArray();

        self::assertArrayNotHasKey('slowest', $detailed);
    }

    public function testPublicArrayNeverContainsChecks(): void
    {
        $ts = new \DateTimeImmutable();
        $result = ProbeResult::fail('db', ProbeKind::Readiness, 1.0, 'down');
        $report = new HealthReport(ProbeStatus::Fail, 'ready', [$result], $result, 1.0, $ts);

        $public = $report->toPublicArray();

        self::assertArrayNotHasKey('checks', $public);
        self::assertArrayNotHasKey('total_latency_ms', $public);
        self::assertArrayNotHasKey('slowest', $public);
    }

    private function makeReport(ProbeStatus $status): HealthReport
    {
        return new HealthReport(
            $status,
            'ready',
            [],
            null,
            0.0,
            new \DateTimeImmutable(),
        );
    }
}
