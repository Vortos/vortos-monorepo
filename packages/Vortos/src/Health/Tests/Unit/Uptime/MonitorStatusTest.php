<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Uptime;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Health\Uptime\MonitorState;
use Vortos\Health\Uptime\MonitorStatus;

final class MonitorStatusTest extends TestCase
{
    public function testUnknownFactoryProducesUnknownState(): void
    {
        $status = MonitorStatus::unknown('m1');

        self::assertSame(MonitorState::Unknown, $status->state);
        self::assertNull($status->latencyMs);
        self::assertTrue($status->isUnknown());
        self::assertFalse($status->isUp());
    }

    public function testIsUpOnlyTrueForUpState(): void
    {
        $status = new MonitorStatus('m1', MonitorState::Up, 12.5, new DateTimeImmutable());

        self::assertTrue($status->isUp());
        self::assertFalse($status->isUnknown());
    }

    public function testRejectsEmptyMonitorId(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MonitorStatus('', MonitorState::Up, 1.0, new DateTimeImmutable());
    }

    public function testRejectsNegativeLatency(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MonitorStatus('m1', MonitorState::Up, -0.1, new DateTimeImmutable());
    }

    public function testAllowsNullLatency(): void
    {
        $status = new MonitorStatus('m1', MonitorState::Down, null, new DateTimeImmutable());

        self::assertNull($status->latencyMs);
    }

    public function testRejectsEmptyFailingRegionEntry(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MonitorStatus('m1', MonitorState::Degraded, 1.0, new DateTimeImmutable(), ['']);
    }

    public function testCarriesFailingRegionsAndIncidentId(): void
    {
        $status = new MonitorStatus(
            'm1',
            MonitorState::Degraded,
            42.0,
            new DateTimeImmutable(),
            ['eu-west'],
            'incident-123',
        );

        self::assertSame(['eu-west'], $status->failingRegions);
        self::assertSame('incident-123', $status->incidentId);
    }
}
