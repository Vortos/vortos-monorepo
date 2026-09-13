<?php

declare(strict_types=1);

namespace Vortos\Backup\Pitr;

/**
 * The long page header PostgreSQL writes at the start of every WAL segment — the one place a segment
 * says how large it is.
 *
 * `wal_segment_size` is a property of the CLUSTER, fixed at initdb (a power of two from 1 MiB to
 * 1 GiB), not a constant of PostgreSQL. Code that assumed 16 MiB worked until production was rebuilt
 * at 2 MiB, after which every restored segment was refused as the wrong size: the daily drill failed
 * on a correct archive, and a real point-in-time restore through the same feeder would have too
 * (FB-65). So nothing here assumes a size. Every segment's first page is an XLogLongPageHeaderData,
 * and it carries the size.
 *
 * Layout, little-endian, with the 64-bit MAXALIGN PostgreSQL uses on every platform this supports:
 *
 *    0  uint16  xlp_magic         XLOG_PAGE_MAGIC — 0xD118 on PostgreSQL 18, on disk as `18 D1`
 *    2  uint16  xlp_info          XLP_LONG_HEADER (0x0002) is set on a segment's first page
 *    4  uint32  xlp_tli
 *    8  uint64  xlp_pageaddr
 *   16  uint32  xlp_rem_len       (padded to 24)
 *   24  uint64  xlp_sysid
 *   32  uint32  xlp_seg_size
 *   36  uint32  xlp_xlog_blcksz
 *
 * Confirmed against a real PostgreSQL 18.6 segment on production: magic d118, info 0x0006,
 * seg_size 2097152, blcksz 8192.
 */
final readonly class XlogLongPageHeader
{
    public const LENGTH            = 40;
    public const XLP_LONG_HEADER   = 0x0002;
    public const MIN_SEGMENT_BYTES = 1024 * 1024;
    public const MAX_SEGMENT_BYTES = 1024 * 1024 * 1024;

    private function __construct(
        public int $magic,
        public int $info,
        public int $segmentBytes,
        public int $blockBytes,
    ) {}

    /** The header of the file at $path, or null when it does not start with a valid long page header. */
    public static function fromFile(string $path): ?self
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }

        try {
            $bytes = fread($handle, self::LENGTH);
        } finally {
            fclose($handle);
        }

        return \is_string($bytes) ? self::fromBytes($bytes) : null;
    }

    public static function fromBytes(string $bytes): ?self
    {
        if (\strlen($bytes) < self::LENGTH) {
            return null;
        }

        $short = unpack('vmagic/vinfo', $bytes);
        $sizes = unpack('Vseg/Vblk', substr($bytes, 32, 8));

        if ($short === false || $sizes === false) {
            return null;
        }

        $magic = (int) $short['magic'];
        $info  = (int) $short['info'];
        $seg   = (int) $sizes['seg'];
        $blk   = (int) $sizes['blk'];

        // The version-stable high byte: 0xD0/0xD1 across every major this framework supports.
        // See WalRestorableInvariant::XLOG_MAGIC_HIGH_BYTES for why the low byte is not pinned.
        if (!\in_array($magic >> 8, [0xD0, 0xD1], true)) {
            return null;
        }

        if (($info & self::XLP_LONG_HEADER) === 0) {
            return null;
        }

        if (!self::isPowerOfTwoWithin($seg, self::MIN_SEGMENT_BYTES, self::MAX_SEGMENT_BYTES)
            || !self::isPowerOfTwoWithin($blk, 1024, 32 * 1024)) {
            return null;
        }

        return new self($magic, $info, $seg, $blk);
    }

    /** Segments in one logical log id; a segment number wraps after this many minus one. */
    public function segmentsPerLogicalId(): int
    {
        return self::segmentsPerLogicalIdFor($this->segmentBytes);
    }

    public static function segmentsPerLogicalIdFor(int $segmentBytes): int
    {
        return intdiv(0x100000000, max(1, $segmentBytes));
    }

    public static function isPlausibleSegmentBytes(int $bytes): bool
    {
        return self::isPowerOfTwoWithin($bytes, self::MIN_SEGMENT_BYTES, self::MAX_SEGMENT_BYTES);
    }

    private static function isPowerOfTwoWithin(int $n, int $min, int $max): bool
    {
        return $n >= $min && $n <= $max && ($n & ($n - 1)) === 0;
    }
}
