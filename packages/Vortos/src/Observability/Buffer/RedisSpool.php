<?php

declare(strict_types=1);

namespace Vortos\Observability\Buffer;

use Redis;
use Throwable;

/**
 * A spool shared by every container in the deployment, backed by a Redis list.
 *
 * WHY THIS IS THE PRODUCTION DEFAULT, and {@see BoundedSpool} is not: a file-backed spool belongs to
 * exactly one container's filesystem, which makes it lossy in two ways that only appear once there is
 * more than one container.
 *
 *  1. **Blue-green destroys it.** The retiring color is removed on cutover, so any alert it had queued
 *     for retry is deleted along with it — a deploy silently discards undelivered alerts.
 *  2. **Not every container can drain.** An HTTP color running FrankenPHP has no supervisord and so
 *     no drainer process; its spool accumulates and is never read by anybody.
 *
 * Moving the queue to Redis makes the spool a property of the *system* rather than of a process: any
 * container that runs a drainer flushes what any other container enqueued, and the queue survives the
 * death of whichever process wrote to it. Redis is already a hard dependency of the stack, reachable
 * from every container, and a list is exactly the right shape — RPUSH to append, LPOP to take oldest
 * first, LTRIM to bound.
 *
 * Bounded by record count rather than bytes: the cap exists so a broken backend cannot exhaust the
 * store, and `LLEN` is O(1) where summing payload sizes is not. Trimming drops the OLDEST records —
 * with alerting, the newest signal is the one worth keeping when something is flooding.
 *
 * Fails soft throughout. Every method swallows connection errors and degrades to "empty", because
 * this sits on the alerting path: a Redis blip must never propagate into the caller that was trying
 * to report a problem.
 */
final class RedisSpool implements SpoolInterface
{
    private const DROPPED_SUFFIX = ':dropped';

    public function __construct(
        private readonly Redis $redis,
        private readonly string $key,
        private readonly int $maxRecords = 10000,
    ) {
    }

    public function enqueue(string $payload, ?int $nowMs = null): bool
    {
        $nowMs ??= (int) (microtime(true) * 1000);

        try {
            $frame = json_encode(['p' => $payload, 't' => $nowMs], JSON_THROW_ON_ERROR);
            $length = $this->redis->rPush($this->key, $frame);

            if (is_int($length) && $length > $this->maxRecords) {
                // Keep the newest $maxRecords; count what fell off so the drop is visible in stats
                // rather than silent.
                $this->redis->lTrim($this->key, -$this->maxRecords, -1);
                $this->redis->incrBy($this->key . self::DROPPED_SUFFIX, $length - $this->maxRecords);
            }

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function drain(int $batch): array
    {
        if ($batch < 1) {
            return [];
        }

        $records = [];

        try {
            for ($i = 0; $i < $batch; $i++) {
                $frame = $this->redis->lPop($this->key);
                if (!is_string($frame) || $frame === '') {
                    break;
                }

                $decoded = json_decode($frame, true);
                if (!is_array($decoded) || !is_string($decoded['p'] ?? null)) {
                    continue; // unreadable frame: drop it rather than stalling the drain forever
                }

                $records[] = new SpoolRecord($decoded['p'], (int) ($decoded['t'] ?? 0));
            }
        } catch (Throwable) {
            // Return whatever was taken; the caller re-spools any it could not deliver.
        }

        return $records;
    }

    public function stats(?int $nowMs = null): SpoolStats
    {
        $nowMs ??= (int) (microtime(true) * 1000);

        try {
            $count = (int) $this->redis->lLen($this->key);
            $dropped = (int) ($this->redis->get($this->key . self::DROPPED_SUFFIX) ?: 0);

            $oldestAge = 0;
            if ($count > 0) {
                $head = $this->redis->lIndex($this->key, 0);
                if (is_string($head)) {
                    $decoded = json_decode($head, true);
                    $enqueuedAt = is_array($decoded) ? (int) ($decoded['t'] ?? 0) : 0;
                    $oldestAge = $enqueuedAt > 0 ? max(0, $nowMs - $enqueuedAt) : 0;
                }
            }

            return new SpoolStats(
                sizeBytes: $count,
                maxBytes: $this->maxRecords,
                recordCount: $count,
                oldestAgeMs: $oldestAge,
                droppedTotal: $dropped,
            );
        } catch (Throwable) {
            return new SpoolStats(0, $this->maxRecords, 0, 0, 0);
        }
    }

    public function isEmpty(): bool
    {
        try {
            return ((int) $this->redis->lLen($this->key)) === 0;
        } catch (Throwable) {
            return true;
        }
    }
}
