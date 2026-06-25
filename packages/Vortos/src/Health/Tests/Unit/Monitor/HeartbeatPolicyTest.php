<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Monitor;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Monitor\HeartbeatPolicy;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\Observability\Heartbeat\HeartbeatStatus;

final class HeartbeatPolicyTest extends TestCase
{
    public function testStartProducesStartPing(): void
    {
        $ping = (new HeartbeatPolicy())->start('m1');

        self::assertSame('m1', $ping->monitorKey);
        self::assertSame(HeartbeatStatus::Start, $ping->status);
    }

    public function testFinishWithAllPassingResultsIsSuccess(): void
    {
        $results = [
            ProbeResult::pass('disk', ProbeKind::Readiness, 1.0),
            ProbeResult::pass('memory', ProbeKind::Readiness, 1.0),
        ];

        $ping = (new HeartbeatPolicy())->finish('m1', $results);

        self::assertSame(HeartbeatStatus::Success, $ping->status);
    }

    public function testFinishWithWarnOnlyIsStillSuccess(): void
    {
        $results = [ProbeResult::warn('disk', ProbeKind::Readiness, 1.0, 'capacity_warn')];

        $ping = (new HeartbeatPolicy())->finish('m1', $results);

        self::assertSame(HeartbeatStatus::Success, $ping->status);
    }

    public function testFinishWithAnyFailIsFail(): void
    {
        $results = [
            ProbeResult::pass('disk', ProbeKind::Readiness, 1.0),
            ProbeResult::fail('cert-expiry', ProbeKind::Readiness, 1.0, 'cert_near_expiry_critical'),
        ];

        $ping = (new HeartbeatPolicy())->finish('m1', $results);

        self::assertSame(HeartbeatStatus::Fail, $ping->status);
        self::assertSame('cert-expiry:cert_near_expiry_critical', $ping->note);
    }

    public function testFinishWithNoResultsIsSuccess(): void
    {
        $ping = (new HeartbeatPolicy())->finish('m1', []);

        self::assertSame(HeartbeatStatus::Success, $ping->status);
    }
}
