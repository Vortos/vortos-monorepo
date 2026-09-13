<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Support;

/**
 * WAL segment bytes laid out exactly as PostgreSQL writes them, so fixtures cannot agree with a wrong
 * assumption in the code under test. Offsets and the sample values come from hexdumping a real
 * PostgreSQL 18.6 segment (magic `18 D1`, info 0x0006, seg_size 2097152, blcksz 8192).
 */
final class WalFileFixture
{
    public static function header(
        int $segmentBytes,
        int $info = 0x0006,
        string $magic = "\x18\xD1",
        int $blockBytes = 8192,
    ): string {
        return $magic
            . pack('v', $info)
            . pack('V', 1)                       // xlp_tli
            . pack('P', 0)                       // xlp_pageaddr
            . pack('V', 0)                       // xlp_rem_len
            . str_repeat("\0", 4)                // MAXALIGN padding to 24
            . pack('P', 0x7684685997218263)      // xlp_sysid
            . pack('V', $segmentBytes)
            . pack('V', $blockBytes);
    }

    /** A whole segment of $fileBytes whose header declares $declaredBytes (defaults to the same). */
    public static function segment(int $fileBytes, ?int $declaredBytes = null): string
    {
        return str_pad(self::header($declaredBytes ?? $fileBytes), $fileBytes, "\0");
    }
}
