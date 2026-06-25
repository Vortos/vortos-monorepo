<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Buffer;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vortos\Observability\Buffer\BoundedSpool;

final class BoundedSpoolTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/vortos-spool-' . bin2hex(random_bytes(8)) . '/q.spool';
    }

    protected function tearDown(): void
    {
        $dir = dirname($this->path);
        if (is_dir($dir)) {
            foreach ((array) glob($dir . '/*') as $f) {
                if (is_string($f)) {
                    @unlink($f);
                }
            }
            @rmdir($dir);
        }
    }

    public function test_fifo_order_preserved(): void
    {
        $spool = new BoundedSpool($this->path, 1024 * 1024);
        $spool->enqueue('a');
        $spool->enqueue('b');
        $spool->enqueue('c');

        $records = $spool->drain(10);

        self::assertSame(['a', 'b', 'c'], array_map(fn ($r) => $r->payload, $records));
    }

    public function test_drain_removes_taken_records(): void
    {
        $spool = new BoundedSpool($this->path, 1024 * 1024);
        $spool->enqueue('a');
        $spool->enqueue('b');

        $first = $spool->drain(1);
        self::assertSame(['a'], array_map(fn ($r) => $r->payload, $first));

        $rest = $spool->drain(10);
        self::assertSame(['b'], array_map(fn ($r) => $r->payload, $rest));
        self::assertTrue($spool->isEmpty());
    }

    public function test_drain_on_empty_returns_nothing(): void
    {
        $spool = new BoundedSpool($this->path, 1024);

        self::assertSame([], $spool->drain(5));
        self::assertTrue($spool->isEmpty());
    }

    public function test_cap_reached_drops_oldest_and_counts(): void
    {
        // Header is 16 bytes; cap fits ~2 small records.
        $spool = new BoundedSpool($this->path, 16 * 3 + 3);

        $spool->enqueue('a'); // 17
        $spool->enqueue('b'); // 34
        $spool->enqueue('c'); // would exceed -> drop oldest 'a'
        $spool->enqueue('d'); // drop 'b'

        $records = $spool->drain(10);
        $payloads = array_map(fn ($r) => $r->payload, $records);

        self::assertNotContains('a', $payloads);
        self::assertContains('d', $payloads);
        self::assertGreaterThanOrEqual(1, $spool->stats()->droppedTotal);
    }

    public function test_record_larger_than_cap_is_dropped(): void
    {
        $spool = new BoundedSpool($this->path, 32);

        $ok = $spool->enqueue(str_repeat('x', 100));

        self::assertFalse($ok);
        self::assertTrue($spool->isEmpty());
        self::assertSame(1, $spool->stats()->droppedTotal);
    }

    public function test_rejects_too_small_cap(): void
    {
        $this->expectException(RuntimeException::class);
        new BoundedSpool($this->path, 4);
    }

    public function test_survives_torn_tail(): void
    {
        $spool = new BoundedSpool($this->path, 1024 * 1024);
        $spool->enqueue('good1');
        $spool->enqueue('good2');

        // Simulate a crash mid-write: append a truncated frame (header claims more than present).
        $torn = pack('N', 999) . pack('N', 0) . pack('J', 0) . 'short';
        file_put_contents($this->path, $torn, FILE_APPEND);

        $records = $spool->drain(10);
        $payloads = array_map(fn ($r) => $r->payload, $records);

        self::assertSame(['good1', 'good2'], $payloads);
    }

    public function test_corrupt_record_stops_reading(): void
    {
        $spool = new BoundedSpool($this->path, 1024 * 1024);
        $spool->enqueue('good');

        // Append a frame with a wrong CRC.
        $bad = pack('N', 3) . pack('N', 12345) . pack('J', 0) . 'bad';
        file_put_contents($this->path, $bad, FILE_APPEND);

        $records = $spool->drain(10);

        self::assertSame(['good'], array_map(fn ($r) => $r->payload, $records));
    }

    public function test_stats_reports_size_count_and_age(): void
    {
        $spool = new BoundedSpool($this->path, 1024 * 1024);
        $spool->enqueue('a', 1000);
        $spool->enqueue('b', 2000);

        $stats = $spool->stats(5000);

        self::assertSame(2, $stats->recordCount);
        self::assertGreaterThan(0, $stats->sizeBytes);
        self::assertSame(4000, $stats->oldestAgeMs);
        self::assertSame(1024 * 1024, $stats->maxBytes);
    }

    public function test_enqueue_preserves_timestamp_on_round_trip(): void
    {
        $spool = new BoundedSpool($this->path, 1024 * 1024);
        $spool->enqueue('a', 123456);

        $records = $spool->drain(1);

        self::assertSame(123456, $records[0]->enqueuedAtMs);
    }

    public function test_binary_payload_round_trips(): void
    {
        $spool = new BoundedSpool($this->path, 1024 * 1024);
        $payload = random_bytes(200);
        $spool->enqueue($payload);

        $records = $spool->drain(1);

        self::assertSame($payload, $records[0]->payload);
    }

    public function test_dropped_counter_persists_across_instances(): void
    {
        $spool = new BoundedSpool($this->path, 32);
        $spool->enqueue(str_repeat('x', 100)); // dropped

        $reopened = new BoundedSpool($this->path, 32);
        self::assertSame(1, $reopened->stats()->droppedTotal);
    }
}
