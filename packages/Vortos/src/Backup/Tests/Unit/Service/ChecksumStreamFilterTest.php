<?php

declare(strict_types=1);

namespace Vortos\Backup\Tests\Unit\Service;

use PHPUnit\Framework\TestCase;
use Vortos\Backup\Domain\BackupChecksum;
use Vortos\Backup\Service\ChecksumStreamFilter;
use Vortos\Backup\Service\HashStreamSink;

final class ChecksumStreamFilterTest extends TestCase
{
    public function test_filter_hashes_bytes_as_they_are_read(): void
    {
        $data = random_bytes(250_000);
        $stream = fopen('php://temp', 'r+b');
        fwrite($stream, $data);
        rewind($stream);

        $sink = new HashStreamSink();
        ChecksumStreamFilter::attach($stream, $sink);

        // Consume the whole stream (as the store upload would).
        $read = '';
        while (!feof($stream)) {
            $read .= (string) fread($stream, 8192);
        }
        fclose($stream);

        $sink->finalize();

        $this->assertSame($data, $read);
        $this->assertSame(strlen($data), $sink->bytes());
        $this->assertTrue($sink->checksum()->equals(BackupChecksum::ofString($data)));
    }

    public function test_sink_cannot_be_read_before_finalize(): void
    {
        $this->expectException(\RuntimeException::class);
        (new HashStreamSink())->checksum();
    }
}
