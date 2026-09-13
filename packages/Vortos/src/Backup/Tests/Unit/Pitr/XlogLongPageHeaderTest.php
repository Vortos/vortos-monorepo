<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Pitr;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vortos\Backup\Pitr\XlogLongPageHeader;
use Vortos\Backup\Tests\Support\WalFileFixture;

final class XlogLongPageHeaderTest extends TestCase
{
    public function test_reads_the_size_production_declares(): void
    {
        $header = XlogLongPageHeader::fromBytes(WalFileFixture::header(2 * 1024 * 1024));

        self::assertNotNull($header);
        self::assertSame(2097152, $header->segmentBytes);
        self::assertSame(8192, $header->blockBytes);
        self::assertSame(0xD118, $header->magic);
        self::assertSame(2048, $header->segmentsPerLogicalId());
    }

    /** @return iterable<string, array{int, int}> */
    public static function sizes(): iterable
    {
        yield '1 MiB'  => [1 * 1024 * 1024, 4096];
        yield '2 MiB'  => [2 * 1024 * 1024, 2048];
        yield '16 MiB' => [16 * 1024 * 1024, 256];
        yield '1 GiB'  => [1024 * 1024 * 1024, 4];
    }

    #[DataProvider('sizes')]
    public function test_segments_per_logical_id_follows_the_segment_size(int $bytes, int $perId): void
    {
        self::assertSame($perId, XlogLongPageHeader::fromBytes(WalFileFixture::header($bytes))?->segmentsPerLogicalId());
    }

    public function test_rejects_a_page_without_the_long_header_flag(): void
    {
        self::assertNull(XlogLongPageHeader::fromBytes(WalFileFixture::header(2 * 1024 * 1024, info: 0x0004)));
    }

    public function test_rejects_bytes_that_are_not_a_wal_page(): void
    {
        self::assertNull(XlogLongPageHeader::fromBytes(WalFileFixture::header(2 * 1024 * 1024, magic: "\x00\x00")));
    }

    public function test_rejects_a_byte_swapped_magic(): void
    {
        self::assertNull(XlogLongPageHeader::fromBytes(WalFileFixture::header(2 * 1024 * 1024, magic: "\xD1\x18")));
    }

    public function test_rejects_a_size_that_is_not_a_power_of_two(): void
    {
        self::assertNull(XlogLongPageHeader::fromBytes(WalFileFixture::header(3 * 1024 * 1024)));
    }

    public function test_rejects_a_size_outside_what_initdb_allows(): void
    {
        self::assertNull(XlogLongPageHeader::fromBytes(WalFileFixture::header(512 * 1024)));
        self::assertNull(XlogLongPageHeader::fromBytes(WalFileFixture::header(2 * 1024 * 1024 * 1024)));
    }

    public function test_rejects_input_shorter_than_the_header(): void
    {
        self::assertNull(XlogLongPageHeader::fromBytes(substr(WalFileFixture::header(2 * 1024 * 1024), 0, 39)));
    }

    public function test_reads_from_a_file_and_tolerates_a_missing_one(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'walhdr');
        file_put_contents($path, WalFileFixture::segment(1024 * 1024));

        try {
            self::assertSame(1048576, XlogLongPageHeader::fromFile($path)?->segmentBytes);
        } finally {
            @unlink($path);
        }

        self::assertNull(XlogLongPageHeader::fromFile($path));
    }
}
