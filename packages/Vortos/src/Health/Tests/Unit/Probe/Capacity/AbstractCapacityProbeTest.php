<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Unit\Probe\Capacity;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Vortos\Health\Probe\Capacity\CapacityReader\InMemoryCapacityReader;
use Vortos\Health\Probe\Capability\HealthCapability;
use Vortos\Health\Probe\Capacity\DiskCapacityProbe;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeStatus;

/**
 * Boundary table (§7): 84/85/94/95% → none/Warn/Warn/Fail, using the default
 * 85% warn / 95% critical thresholds. Disk stands in for all three capacity probes
 * since the threshold logic lives in the shared abstract base.
 */
final class AbstractCapacityProbeTest extends TestCase
{
    /** @return iterable<string, array{float, ProbeStatus}> */
    public static function boundaryProvider(): iterable
    {
        yield 'below warn (84%)' => [84.0, ProbeStatus::Pass];
        yield 'at warn boundary (85%)' => [85.0, ProbeStatus::Warn];
        yield 'between warn and critical (94%)' => [94.0, ProbeStatus::Warn];
        yield 'at critical boundary (95%)' => [95.0, ProbeStatus::Fail];
        yield 'well below (0%)' => [0.0, ProbeStatus::Pass];
        yield 'fully saturated (100%)' => [100.0, ProbeStatus::Fail];
    }

    /** @dataProvider boundaryProvider */
    public function testThresholdBoundaries(float $usedPct, ProbeStatus $expected): void
    {
        $probe = new DiskCapacityProbe(new InMemoryCapacityReader(diskUsedPct: $usedPct));

        self::assertSame($expected, $probe->check()->status);
    }

    public function testUnreadableReaderDegradesToWarnNotFail(): void
    {
        $probe = new DiskCapacityProbe(new InMemoryCapacityReader(diskUsedPct: null));
        $result = $probe->check();

        self::assertSame(ProbeStatus::Warn, $result->status);
        self::assertSame('capacity_unreadable', $result->errorCode);
    }

    public function testReadinessDegradesOnWarnWhileNeverDeclaringLivenessKind(): void
    {
        $probe = new DiskCapacityProbe(new InMemoryCapacityReader(diskUsedPct: 90.0));

        self::assertSame(ProbeKind::Readiness, $probe->kind());
        self::assertFalse($probe->capabilities()->supports(HealthCapability::DependencyCheck));
    }

    public function testRejectsWarnPctOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DiskCapacityProbe(new InMemoryCapacityReader(), warnPct: 0.0);
    }

    public function testRejectsCriticalPctOutOfRange(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DiskCapacityProbe(new InMemoryCapacityReader(), criticalPct: 150.0);
    }

    public function testRejectsWarnGreaterThanOrEqualCritical(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new DiskCapacityProbe(new InMemoryCapacityReader(), warnPct: 95.0, criticalPct: 95.0);
    }

    public function testDetailCarriesUsedPct(): void
    {
        $probe = new DiskCapacityProbe(new InMemoryCapacityReader(diskUsedPct: 42.5));
        $result = $probe->check();

        self::assertSame(42.5, $result->detail['used_pct']);
    }
}
