<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Pitr;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\CompressionCodec;
use Vortos\Backup\Domain\Exception\BackupException;
use Vortos\Backup\Pitr\WalCompression;
use Vortos\Backup\Pitr\WalCompressionSettings;
use Vortos\Backup\Tests\Unit\Crypto\ShortReadStreamWrapper;

/**
 * Codec mechanics for archived WAL, including the regression guard on storage amplification.
 *
 * The bug this file exists for was not a crash. WAL shipped correctly, restored correctly, and
 * quietly consumed ~87x the storage it needed to, because `archive_timeout` forces a segment switch
 * on a clock and an archived segment is a full 16 MiB regardless of how much log it holds. Nothing
 * in the suite could observe that, so the only thing that would ever have reported it is a bill.
 * {@see test_a_sparse_segment_compresses_by_at_least_an_order_of_magnitude} is the standing check.
 */
final class WalCompressionTest extends TestCase
{
    /**
     * A segment shaped like the ones this actually ships: a page header, a modest run of real
     * records, and zero padding out to 16 MiB. Randomness in the records matters — zeros alone
     * would compress arbitrarily well and prove nothing about the real ratio.
     */
    private function sparseSegment(int $recordBytes = 300 * 1024): string
    {
        return str_pad("\x98\xD0\x01\x00" . random_bytes($recordBytes), 16 * 1024 * 1024, "\0");
    }

    private function deflate(string $payload, int $level = WalCompression::DEFAULT_LEVEL): string
    {
        // Read through the SHORT-READ wrapper, not php://temp. A pipe and an object-store download
        // both short-read, php://temp never does, and that difference is exactly how a framing bug
        // that made every encrypted backup undecryptable passed the entire suite once already.
        ShortReadStreamWrapper::register();
        $handle = fopen(ShortReadStreamWrapper::urlFor($payload), 'rb');
        self::assertIsResource($handle);

        WalCompression::attachDeflate($handle, $level);
        $compressed = stream_get_contents($handle);
        fclose($handle);

        self::assertIsString($compressed);

        return $compressed;
    }

    /** @param iterable<int, string> $chunks */
    private function inflateAll(iterable $chunks): string
    {
        $out = '';
        foreach (WalCompression::maybeInflate($chunks) as $chunk) {
            $out .= $chunk;
        }

        return $out;
    }

    public function test_round_trips_a_segment_byte_for_byte(): void
    {
        $segment = $this->sparseSegment();

        $this->assertSame($segment, $this->inflateAll([$this->deflate($segment)]));
    }

    /**
     * The read path is fed by an object-store download, which returns short reads. Chunking the
     * compressed member at a size that shares no factor with any internal buffer forces inflate
     * boundaries to fall mid-window.
     */
    public function test_round_trips_across_pathological_chunk_boundaries(): void
    {
        $segment    = $this->sparseSegment();
        $compressed = $this->deflate($segment);

        foreach ([1, 7, 997, 65535] as $chunkSize) {
            $this->assertSame(
                $segment,
                $this->inflateAll(str_split($compressed, $chunkSize)),
                "round trip failed at chunk size {$chunkSize}",
            );
        }
    }

    /**
     * Backward compatibility, and the reason detection is per segment rather than per config: the
     * store holds ~28,000 segments archived before compression existed, and a recovery spanning the
     * cutover must replay both sides of it.
     */
    public function test_passes_uncompressed_legacy_segments_through_untouched(): void
    {
        $segment = $this->sparseSegment();

        $this->assertSame($segment, $this->inflateAll(str_split($segment, 65536)));
    }

    public function test_passes_through_a_segment_shorter_than_the_gzip_magic(): void
    {
        $this->assertSame('x', $this->inflateAll(['x']));
        $this->assertSame('', $this->inflateAll([]));
    }

    /**
     * The failure mode that matters most on this path. A truncated member inflates to a short but
     * WELL-FORMED prefix of real WAL; Postgres would replay it, stop, and report a successful
     * recovery to an earlier point in time than was asked for.
     */
    public function test_rejects_a_truncated_member_rather_than_replaying_a_partial_segment(): void
    {
        $compressed = $this->deflate($this->sparseSegment());

        foreach (['trailer' => 8, 'partial-crc' => 4, 'half' => intdiv(strlen($compressed), 2)] as $label => $cut) {
            try {
                $this->inflateAll([substr($compressed, 0, strlen($compressed) - $cut)]);
                $this->fail("truncation '{$label}' was not rejected");
            } catch (BackupException $e) {
                $this->assertStringContainsString('truncated', $e->getMessage());
            }
        }
    }

    public function test_rejects_a_corrupt_member(): void
    {
        $compressed = $this->deflate($this->sparseSegment());
        $corrupt    = substr($compressed, 0, 200) . 'XXXX' . substr($compressed, 204);

        $this->expectException(BackupException::class);
        $this->inflateAll([$corrupt]);
    }

    /**
     * THE REGRESSION GUARD. If someone reverts the codec, widens the pipeline in a way that stops
     * compressing, or picks a level that does nothing, this is what says so — in CI, rather than on
     * an invoice three weeks later.
     *
     * The threshold is deliberately loose. Production measured 51x on a real sparse segment; 10x is
     * far below that and still impossible to satisfy by accident, so this fails on "compression
     * silently stopped happening" without becoming a flaky assertion about zlib's exact output.
     */
    public function test_a_sparse_segment_compresses_by_at_least_an_order_of_magnitude(): void
    {
        $segment    = $this->sparseSegment();
        $compressed = $this->deflate($segment);
        $ratio      = strlen($segment) / strlen($compressed);

        $this->assertGreaterThan(10.0, $ratio, sprintf(
            'WAL compression ratio collapsed to %.1fx (%d -> %d bytes). A 16 MiB segment holding '
            . '~300 KB of records must compress at least 10x; anything less means the codec is not '
            . 'being applied.',
            $ratio,
            strlen($segment),
            strlen($compressed),
        ));
    }

    /** Even level 1 removes the padding — the win is runs of zeros, not entropy coding. */
    public function test_the_lowest_level_still_removes_the_padding(): void
    {
        $segment = $this->sparseSegment();
        $ratio   = strlen($segment) / strlen($this->deflate($segment, 1));

        $this->assertGreaterThan(10.0, $ratio);
    }

    public function test_rejects_a_codec_this_build_cannot_apply(): void
    {
        // Zstd is a real CompressionCodec case but needs a non-core extension. It must fail loudly
        // rather than degrade to shipping uncompressed, which is the fault being fixed.
        $this->expectException(BackupException::class);
        WalCompression::assertSupported(CompressionCodec::Zstd);
    }

    public function test_rejects_an_out_of_range_level(): void
    {
        ShortReadStreamWrapper::register();
        $handle = fopen(ShortReadStreamWrapper::urlFor('x'), 'rb');
        self::assertIsResource($handle);

        try {
            $this->expectException(BackupException::class);
            WalCompression::attachDeflate($handle, 10);
        } finally {
            fclose($handle);
        }
    }

    public function test_settings_report_their_state_for_the_runbook(): void
    {
        $this->assertFalse(WalCompressionSettings::disabled()->enabled());
        $this->assertStringContainsString('16 MiB', WalCompressionSettings::disabled()->describe());

        $on = new WalCompressionSettings(CompressionCodec::Gzip, 6);
        $this->assertTrue($on->enabled());
        $this->assertSame('gzip (level 6)', $on->describe());
    }

    public function test_settings_reject_an_unsupported_codec_at_construction(): void
    {
        $this->expectException(BackupException::class);
        new WalCompressionSettings(CompressionCodec::Zstd);
    }
}
