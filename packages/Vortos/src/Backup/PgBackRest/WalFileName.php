<?php

declare(strict_types=1);

namespace Vortos\Backup\PgBackRest;

use InvalidArgumentException;

/**
 * A WAL file name (`TTTTTTTTXXXXXXXXYYYYYYYY`: timeline, log, segment within log) as a position.
 *
 * The segment field counts segments within a 4 GiB "log", so how many there are per log depends on the
 * cluster's wal_segment_size: 256 at 16 MiB, 2048 at this cluster's 2 MiB. Comparing names as strings
 * orders them, but only this arithmetic says how FAR apart two are.
 */
final readonly class WalFileName
{
    private function __construct(
        public string $name,
        public int $timeline,
        public int $log,
        public int $segment,
    ) {}

    public static function parse(string $name): self
    {
        if (preg_match('/^([0-9A-F]{8})([0-9A-F]{8})([0-9A-F]{8})$/', $name, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('Not a WAL segment name: "%s".', $name));
        }

        return new self($name, (int) hexdec($m[1]), (int) hexdec($m[2]), (int) hexdec($m[3]));
    }

    /** The file holding an LSN (`X/Y`), as pg_walfile_name() names it on the given timeline. */
    public static function containing(string $lsn, int $timeline, int $segmentBytes): self
    {
        if (preg_match('/^([0-9A-Fa-f]{1,8})\/([0-9A-Fa-f]{1,8})$/', $lsn, $m) !== 1) {
            throw new InvalidArgumentException(sprintf('Not an LSN: "%s".', $lsn));
        }
        $position = ((int) hexdec($m[1]) << 32) | (int) hexdec($m[2]);
        $sequence = intdiv($position, self::assertSegmentBytes($segmentBytes));
        $perLog = intdiv(0x1_0000_0000, $segmentBytes);

        return self::parse(sprintf('%08X%08X%08X', $timeline, intdiv($sequence, $perLog), $sequence % $perLog));
    }

    /** Position in segments from the start of the log stream, independent of timeline. */
    public function sequence(int $segmentBytes): int
    {
        return $this->log * intdiv(0x1_0000_0000, self::assertSegmentBytes($segmentBytes)) + $this->segment;
    }

    private static function assertSegmentBytes(int $segmentBytes): int
    {
        // initdb accepts powers of two from 1 MiB to 1 GiB.
        if ($segmentBytes < 1 << 20 || $segmentBytes > 1 << 30 || ($segmentBytes & ($segmentBytes - 1)) !== 0) {
            throw new InvalidArgumentException(sprintf('Not a WAL segment size: %d bytes.', $segmentBytes));
        }

        return $segmentBytes;
    }
}
