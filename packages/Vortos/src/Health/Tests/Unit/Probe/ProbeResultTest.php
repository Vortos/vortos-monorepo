<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe;

use PHPUnit\Framework\TestCase;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeResult;
use Vortos\Health\Probe\ProbeStatus;

final class ProbeResultTest extends TestCase
{
    public function testPassFactory(): void
    {
        $r = ProbeResult::pass('db', ProbeKind::Readiness, 1.5, ['version' => '15']);

        self::assertSame(ProbeStatus::Pass, $r->status);
        self::assertSame('db', $r->name);
        self::assertSame(ProbeKind::Readiness, $r->kind);
        self::assertSame(1.5, $r->latencyMs);
        self::assertSame(['version' => '15'], $r->detail);
        self::assertNull($r->errorCode);
        self::assertFalse($r->timedOut);
        self::assertFalse($r->skipped);
        self::assertTrue($r->isHealthy());
    }

    public function testWarnFactory(): void
    {
        $r = ProbeResult::warn('redis', ProbeKind::Readiness, 2.0, 'high_latency');

        self::assertSame(ProbeStatus::Warn, $r->status);
        self::assertSame('high_latency', $r->errorCode);
        self::assertTrue($r->isHealthy());
    }

    public function testFailFactory(): void
    {
        $r = ProbeResult::fail('db', ProbeKind::Readiness, 3.0, 'unreachable');

        self::assertSame(ProbeStatus::Fail, $r->status);
        self::assertSame('unreachable', $r->errorCode);
        self::assertFalse($r->isHealthy());
    }

    public function testTimedOutFactory(): void
    {
        $r = ProbeResult::timedOut('kafka', ProbeKind::Readiness, 5000.0, 3000);

        self::assertSame(ProbeStatus::Fail, $r->status);
        self::assertTrue($r->timedOut);
        self::assertSame('probe_timeout', $r->errorCode);
        self::assertSame(['deadline_ms' => 3000], $r->detail);
        self::assertFalse($r->isHealthy());
    }

    public function testSkippedFactory(): void
    {
        $r = ProbeResult::skipped('slow', ProbeKind::Readiness);

        self::assertSame(ProbeStatus::Fail, $r->status);
        self::assertTrue($r->skipped);
        self::assertSame('budget_exhausted', $r->errorCode);
        self::assertSame(0.0, $r->latencyMs);
    }

    public function testPublicArrayOnlyExposesStatus(): void
    {
        $r = ProbeResult::fail('db', ProbeKind::Readiness, 5.0, 'unreachable', ['hint' => 'down']);

        $public = $r->toPublicArray();

        self::assertSame(['status' => 'fail'], $public);
    }

    public function testDetailedArrayContainsAllFields(): void
    {
        $r = ProbeResult::fail('db', ProbeKind::Readiness, 5.123, 'unreachable', ['hint' => 'down']);

        $detailed = $r->toDetailedArray();

        self::assertSame('fail', $detailed['status']);
        self::assertSame('readiness', $detailed['kind']);
        self::assertSame(5.12, $detailed['latency_ms']);
        self::assertSame('unreachable', $detailed['error_code']);
        self::assertSame(['hint' => 'down'], $detailed['detail']);
    }

    public function testDetailedArrayOmitsErrorsWhenNotIncluded(): void
    {
        $r = ProbeResult::fail('db', ProbeKind::Readiness, 5.0, 'unreachable', ['hint' => 'down']);

        $detailed = $r->toDetailedArray(includeErrors: false);

        self::assertArrayNotHasKey('error_code', $detailed);
        self::assertArrayNotHasKey('detail', $detailed);
    }

    public function testDetailedArrayShowsTimedOut(): void
    {
        $r = ProbeResult::timedOut('slow', ProbeKind::Readiness, 4000.0, 3000);

        $detailed = $r->toDetailedArray();

        self::assertTrue($detailed['timed_out']);
    }

    public function testDetailedArrayShowsSkipped(): void
    {
        $r = ProbeResult::skipped('slow', ProbeKind::Readiness);

        $detailed = $r->toDetailedArray();

        self::assertTrue($detailed['skipped']);
    }

    public function testPassDetailedArrayHasNoErrorFields(): void
    {
        $r = ProbeResult::pass('db', ProbeKind::Readiness, 1.0);

        $detailed = $r->toDetailedArray();

        self::assertArrayNotHasKey('error_code', $detailed);
        self::assertArrayNotHasKey('detail', $detailed);
        self::assertArrayNotHasKey('timed_out', $detailed);
        self::assertArrayNotHasKey('skipped', $detailed);
    }
}
