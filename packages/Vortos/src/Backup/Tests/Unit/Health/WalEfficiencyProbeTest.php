<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Health;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Health\WalEfficiency;
use Vortos\Backup\Health\WalEfficiencyProbe;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Health\Probe\ProbeKind;
use Vortos\Health\Probe\ProbeStatus;

/**
 * The probe that would have caught the 87x amplification incident on day one instead of week three.
 */
final class WalEfficiencyProbeTest extends TestCase
{
    private function probe(int $segments, int $bytes, float $minRatio = 4.0, int $budget = 5 * 1024 * 1024 * 1024): WalEfficiencyProbe
    {
        $catalog = new class ($segments, $bytes) implements WalVolumeReadModelInterface {
            public function __construct(private readonly int $segments, private readonly int $bytes) {}

            public function walVolumeSince(DatabaseEngine $engine, string $environment, DateTimeImmutable $from): array
            {
                return ['segments' => $this->segments, 'bytes' => $this->bytes];
            }

        };

        return new WalEfficiencyProbe(
            $catalog,
            new FixedClock(new DateTimeImmutable('2026-08-15 12:00:00')),
            'production',
            $minRatio,
            $budget,
        );
    }

    /** 1,440 segments/day at a full 16 MiB each — the exact production state that went unnoticed. */
    public function test_fails_when_segments_ship_at_full_size(): void
    {
        $result = $this->probe(1440, 1440 * WalEfficiency::SEGMENT_BYTES)->check();

        $this->assertSame(ProbeStatus::Fail, $result->status);
        $this->assertSame('wal_compression_ineffective', $result->errorCode);
        $this->assertSame(1.0, $result->detail['compression_ratio']);
    }

    /** The same cadence, compressed at the ratio measured in production. */
    public function test_passes_when_compression_is_working(): void
    {
        $result = $this->probe(1440, 1440 * 325_000)->check();

        $this->assertSame(ProbeStatus::Pass, $result->status);
        $this->assertGreaterThan(40.0, $result->detail['compression_ratio']);
    }

    /**
     * Compression working is not the same as spend being sane. A write explosion compresses fine and
     * would read as perfectly healthy on a ratio check alone.
     */
    public function test_fails_when_volume_exceeds_the_budget_despite_good_compression(): void
    {
        // 40,000 well-compressed segments/day — ratio is excellent, the bill is not.
        $result = $this->probe(40_000, 40_000 * 325_000, budget: 5 * 1024 * 1024 * 1024)->check();

        $this->assertSame(ProbeStatus::Fail, $result->status);
        $this->assertSame('wal_volume_over_budget', $result->errorCode);
    }

    /**
     * A quiet window must not alarm. A probe that cries wolf on idle hours gets ignored, and being
     * ignored is how the original fault survived three weeks of green dashboards.
     */
    public function test_reports_indeterminate_rather_than_failing_on_a_quiet_window(): void
    {
        $result = $this->probe(3, 3 * WalEfficiency::SEGMENT_BYTES)->check();

        $this->assertSame(ProbeStatus::Warn, $result->status);
        $this->assertSame('wal_efficiency_indeterminate', $result->errorCode);
    }

    public function test_reports_indeterminate_on_an_empty_window(): void
    {
        $this->assertSame(ProbeStatus::Warn, $this->probe(0, 0)->check()->status);
    }

    /**
     * MONITORING kind is not a detail. A readiness-kind probe here would drop the color out of the
     * edge's upstream pool over a storage-efficiency concern, turning a cost problem into an outage.
     */
    public function test_is_monitoring_kind_so_it_can_never_fail_a_readiness_gate(): void
    {
        $this->assertSame(ProbeKind::Monitoring, $this->probe(1440, 1440 * 325_000)->kind());
    }

    public function test_an_unreachable_catalog_is_indeterminate_not_a_failure(): void
    {
        $catalog = new class implements WalVolumeReadModelInterface {
            public function walVolumeSince(DatabaseEngine $engine, string $environment, DateTimeImmutable $from): array
            {
                throw new \RuntimeException('connection refused');
            }

        };

        $probe = new WalEfficiencyProbe($catalog, new FixedClock(new DateTimeImmutable('now')), 'production');

        $this->assertSame(ProbeStatus::Warn, $probe->check()->status);
    }

    public function test_projects_the_daily_bill_independently_of_the_window(): void
    {
        $efficiency = new WalEfficiency('production', 720, 720 * 16 * 1024 * 1024, 12);

        // Half a day of full-size segments extrapolates to the full-day figure.
        $this->assertEqualsWithDelta(
            1440 * 16 * 1024 * 1024,
            $efficiency->projectedDailyBytes(),
            1.0,
        );
        $this->assertEqualsWithDelta(1.0, $efficiency->compressionRatio(), 0.001);
    }
}
