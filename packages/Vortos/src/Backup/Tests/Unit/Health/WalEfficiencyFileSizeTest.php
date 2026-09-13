<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Health;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Backup\Catalog\WalVolumeReadModelInterface;
use Vortos\Backup\Domain\DatabaseEngine;
use Vortos\Backup\Health\WalEfficiencyProbe;
use Vortos\Backup\Health\WalFileSizeResolverInterface;
use Vortos\Backup\Tests\Support\FixedClock;
use Vortos\Health\Probe\ProbeStatus;

/**
 * FB-65. On a cluster built at 2 MiB, dividing by an assumed 16 MiB reported an uncompressed archive
 * as 8x compressed — above the 4x floor — so the codec-regression alarm could not fire.
 */
final class WalEfficiencyFileSizeTest extends TestCase
{
    private const MIB2 = 2 * 1024 * 1024;

    private function probe(int $segments, int $bytes, ?WalFileSizeResolverInterface $resolver): WalEfficiencyProbe
    {
        $catalog = new class ($segments, $bytes) implements WalVolumeReadModelInterface {
            public function __construct(private readonly int $segments, private readonly int $bytes) {}

            public function walVolumeSince(DatabaseEngine $engine, string $environment, DateTimeImmutable $from): array
            {
                return ['segments' => $this->segments, 'bytes' => $this->bytes];
            }

            public function newestWalSegmentName(DatabaseEngine $engine, string $environment): ?string
            {
                return null;
            }
        };

        return new WalEfficiencyProbe(
            catalog: $catalog,
            clock: new FixedClock(new DateTimeImmutable('2026-09-13 12:00:00')),
            environment: 'production',
            minCompressionRatio: 4.0,
            maxDailyBytes: 5 * 1024 * 1024 * 1024,
            segmentSize: $resolver,
        );
    }

    private function resolver(int $bytes): WalFileSizeResolverInterface
    {
        return new class ($bytes) implements WalFileSizeResolverInterface {
            public function __construct(private readonly int $bytes) {}

            public function segmentBytes(): int
            {
                return $this->bytes;
            }
        };
    }

    public function test_an_uncompressed_2_mib_archive_fails_when_the_real_segment_size_is_known(): void
    {
        $result = $this->probe(1296, 1296 * self::MIB2, $this->resolver(self::MIB2))->check();

        self::assertSame(ProbeStatus::Fail, $result->status);
        self::assertSame('wal_compression_ineffective', $result->errorCode);
        self::assertSame(1.0, $result->detail['compression_ratio']);
        self::assertSame('server', $result->detail['segment_bytes_source']);
    }

    /** The blindness itself, kept visible: without the real size the same archive reads as 8x. */
    public function test_without_a_resolver_the_default_is_assumed_and_says_so(): void
    {
        $result = $this->probe(1296, 1296 * self::MIB2, null)->check();

        self::assertSame(8.0, $result->detail['compression_ratio']);
        self::assertSame('assumed_default', $result->detail['segment_bytes_source']);
    }

    public function test_a_compressed_2_mib_archive_passes(): void
    {
        // Production after the rebuild: ~69 kB stored per 2 MiB segment.
        $result = $this->probe(1296, 1296 * 69_000, $this->resolver(self::MIB2))->check();

        self::assertSame(ProbeStatus::Pass, $result->status);
        self::assertSame(self::MIB2, $result->detail['segment_bytes']);
    }

    public function test_an_unreadable_segment_size_leaves_compression_unjudged_instead_of_guessing(): void
    {
        $throwing = new class implements WalFileSizeResolverInterface {
            public function segmentBytes(): int
            {
                throw new RuntimeException('connection refused');
            }
        };

        $result = $this->probe(1296, 1296 * self::MIB2, $throwing)->check();

        self::assertSame(ProbeStatus::Warn, $result->status);
        self::assertSame('wal_efficiency_indeterminate', $result->errorCode);
        self::assertNull($result->detail['compression_ratio']);
        self::assertSame('connection refused', $result->detail['error']);
    }

    public function test_an_implausible_segment_size_is_treated_as_unreadable(): void
    {
        $result = $this->probe(1296, 1296 * 69_000, $this->resolver(3 * 1024 * 1024))->check();

        self::assertSame(ProbeStatus::Warn, $result->status);
        self::assertSame('unreadable', $result->detail['segment_bytes_source']);
    }

    public function test_volume_is_still_judged_when_the_segment_size_is_unreadable(): void
    {
        $throwing = new class implements WalFileSizeResolverInterface {
            public function segmentBytes(): int
            {
                throw new RuntimeException('down');
            }
        };

        // 1296 segments at 8 MiB stored each is ~10 GB/day, over the 5 GB budget.
        $result = $this->probe(1296, 1296 * 8 * 1024 * 1024, $throwing)->check();

        self::assertSame(ProbeStatus::Fail, $result->status);
        self::assertSame('wal_volume_over_budget', $result->errorCode);
    }
}
