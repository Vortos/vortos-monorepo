<?php

declare(strict_types=1);

namespace Vortos\Observability\Buffer;

use RuntimeException;

/**
 * A bounded, crash-safe, on-disk FIFO spool for telemetry that can't be delivered
 * right now (the error-sink durability layer of §12.4).
 *
 * The hard requirement: a long backend outage must **never** fill the disk and take
 * down the app host, and the emit path must **never** block. This spool therefore:
 *
 *  - is **byte-capped** ({@see $maxBytes}); when full it **drops the oldest** records
 *    to admit a new one and increments a persistent `droppedTotal` counter — it never
 *    grows unbounded and never blocks;
 *  - is **crash-safe**: each record is length-prefixed + CRC32-checked; a torn tail
 *    (half-written record after a crash) is detected on read and truncated on the
 *    next write, so a partial write can never corrupt the queue;
 *  - is **multi-process-safe**: every mutating op holds an exclusive `flock`;
 *  - rewrites atomically (temp file + `rename()`), mirroring `FileSecretStore`.
 *
 * Record frame (all integers big-endian): `len(4) | crc32(4) | enqueuedAtMs(8) | payload(len)`.
 */
final class BoundedSpool implements SpoolInterface
{
    /** len(4) + crc32(4) + enqueuedAtMs(8). */
    private const HEADER_BYTES = 16;

    public function __construct(
        private readonly string $path,
        private readonly int $maxBytes,
    ) {
        if ($maxBytes < self::HEADER_BYTES + 1) {
            throw new RuntimeException("BoundedSpool maxBytes must be at least " . (self::HEADER_BYTES + 1) . ".");
        }
    }

    /**
     * Append a payload. O(1) amortized, bounded, never blocks on the backend. Returns
     * false (and counts a drop) only when the payload alone cannot fit the cap.
     */
    public function enqueue(string $payload, ?int $nowMs = null): bool
    {
        $nowMs ??= self::nowMs();
        $frame = $this->frame($payload, $nowMs);

        // A single record larger than the whole cap can never be stored: drop it.
        if (strlen($frame) > $this->maxBytes) {
            $this->withLock(function ($handle): void {
                $this->bumpDropped(1);
            });

            return false;
        }

        $this->withLock(function ($handle) use ($frame): void {
            $records = $this->readFrames($handle);
            $records[] = $frame;

            $dropped = 0;
            $total = array_sum(array_map('strlen', $records));
            // Drop oldest until the new record fits under the cap.
            while ($total > $this->maxBytes && count($records) > 1) {
                $removed = array_shift($records);
                $total -= strlen((string) $removed);
                $dropped++;
            }

            $this->rewrite($records);
            if ($dropped > 0) {
                $this->bumpDropped($dropped);
            }
        });

        return true;
    }

    /**
     * Remove and return up to $batch oldest records (FIFO). Out-of-band drain — the
     * flush listener / cron worker calls this, never the request path.
     *
     * @return list<SpoolRecord>
     */
    public function drain(int $batch): array
    {
        if ($batch < 1) {
            return [];
        }

        $drained = [];
        $this->withLock(function ($handle) use ($batch, &$drained): void {
            $records = $this->readRecords($handle);
            $taken = array_slice($records, 0, $batch);
            $remaining = array_slice($records, $batch);

            $frames = array_map(fn (SpoolRecord $r): string => $this->frame($r->payload, $r->enqueuedAtMs), $remaining);
            $this->rewrite($frames);

            $drained = $taken;
        });

        return $drained;
    }

    public function stats(?int $nowMs = null): SpoolStats
    {
        $nowMs ??= self::nowMs();
        $records = [];
        $this->withLock(function ($handle) use (&$records): void {
            $records = $this->readRecords($handle);
        });

        $oldestAge = 0;
        if ($records !== []) {
            $oldestAge = max(0, $nowMs - $records[0]->enqueuedAtMs);
        }

        return new SpoolStats(
            sizeBytes: is_file($this->path) ? (int) filesize($this->path) : 0,
            maxBytes: $this->maxBytes,
            recordCount: count($records),
            oldestAgeMs: $oldestAge,
            droppedTotal: $this->readDropped(),
        );
    }

    public function isEmpty(): bool
    {
        return $this->stats()->recordCount === 0;
    }

    // --- internals -----------------------------------------------------------

    private function frame(string $payload, int $nowMs): string
    {
        return pack('N', strlen($payload)) . pack('N', crc32($payload)) . pack('J', $nowMs) . $payload;
    }

    /**
     * @param resource $handle
     * @return list<string> raw frames, oldest first; stops at the first torn/corrupt record
     */
    private function readFrames($handle): array
    {
        $records = [];
        foreach ($this->readRecords($handle) as $record) {
            $records[] = $this->frame($record->payload, $record->enqueuedAtMs);
        }

        return $records;
    }

    /**
     * @param resource $handle
     * @return list<SpoolRecord> oldest first; stops at the first torn/corrupt record (crash tail)
     */
    private function readRecords($handle): array
    {
        rewind($handle);
        $blob = stream_get_contents($handle);
        if ($blob === false || $blob === '') {
            return [];
        }

        $records = [];
        $offset = 0;
        $length = strlen($blob);

        while ($offset + self::HEADER_BYTES <= $length) {
            /** @var array{1:int} $lenUnpack */
            $lenUnpack = unpack('N', substr($blob, $offset, 4));
            $payloadLen = $lenUnpack[1];
            /** @var array{1:int} $crcUnpack */
            $crcUnpack = unpack('N', substr($blob, $offset + 4, 4));
            $crc = $crcUnpack[1];
            /** @var array{1:int} $tsUnpack */
            $tsUnpack = unpack('J', substr($blob, $offset + 8, 8));
            $ts = $tsUnpack[1];

            $payloadStart = $offset + self::HEADER_BYTES;
            // Torn tail: declared payload runs past EOF → stop (will be truncated on next write).
            if ($payloadStart + $payloadLen > $length) {
                break;
            }

            $payload = substr($blob, $payloadStart, $payloadLen);
            // Corrupt record → stop reading here (treat the rest as untrustworthy tail).
            if (crc32($payload) !== $crc) {
                break;
            }

            $records[] = new SpoolRecord($payload, $ts);
            $offset = $payloadStart + $payloadLen;
        }

        return $records;
    }

    /**
     * Atomically replace the spool with exactly these frames (temp + rename).
     *
     * @param list<string> $frames
     */
    private function rewrite(array $frames): void
    {
        $this->ensureDir();
        $temp = $this->path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temp, implode('', $frames)) === false) {
            throw new RuntimeException('Failed to write spool temp file: ' . $temp);
        }
        if (!rename($temp, $this->path)) {
            @unlink($temp);
            throw new RuntimeException('Failed to atomically replace spool at: ' . $this->path);
        }
    }

    /**
     * Open the spool (creating it), take an exclusive lock, run $fn, then release.
     *
     * @param callable(resource):void $fn
     */
    private function withLock(callable $fn): void
    {
        $this->ensureDir();
        $handle = fopen($this->path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Failed to open spool at: ' . $this->path);
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Failed to lock spool at: ' . $this->path);
            }
            try {
                $fn($handle);
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    private function ensureDir(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Failed to create spool directory: ' . $dir);
        }
    }

    private function droppedPath(): string
    {
        return $this->path . '.dropped';
    }

    private function readDropped(): int
    {
        $path = $this->droppedPath();
        if (!is_file($path)) {
            return 0;
        }
        $raw = file_get_contents($path);

        return $raw === false ? 0 : max(0, (int) trim($raw));
    }

    private function bumpDropped(int $by): void
    {
        $next = $this->readDropped() + $by;
        $temp = $this->droppedPath() . '.' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temp, (string) $next) !== false) {
            @rename($temp, $this->droppedPath());
        }
    }

    private static function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }
}
