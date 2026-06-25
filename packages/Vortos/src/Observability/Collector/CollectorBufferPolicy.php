<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

use InvalidArgumentException;

/**
 * Bounded buffering + cardinality policy for the generated collector config.
 *
 * Every value here exists to keep a backend outage from harming the app host: the
 * `file_storage` persistent queue is disk-bounded, the `memory_limiter` caps RAM, and
 * the cardinality deny-list drops per-request attributes that would otherwise explode
 * the metrics backend's cardinality (and bill).
 */
final readonly class CollectorBufferPolicy
{
    /**
     * @param string            $storageDir        Host dir the collector's file_storage extension persists to
     * @param int               $memoryLimitMib    Hard RAM cap for the collector (memory_limiter)
     * @param int               $memorySpikeMib    Spike allowance above the soft limit
     * @param int               $retryMaxSeconds   Max elapsed retry time before a batch is dropped
     * @param list<string>      $cardinalityDenyList Attribute keys deleted before export (per-request, high-cardinality)
     */
    public function __construct(
        public string $storageDir = '/var/lib/otelcol/storage',
        public int $memoryLimitMib = 256,
        public int $memorySpikeMib = 64,
        public int $retryMaxSeconds = 300,
        public array $cardinalityDenyList = self::DEFAULT_CARDINALITY_DENY_LIST,
    ) {
        if ($storageDir === '') {
            throw new InvalidArgumentException('CollectorBufferPolicy storageDir must be non-empty.');
        }
        if ($memoryLimitMib < 32) {
            throw new InvalidArgumentException('CollectorBufferPolicy memoryLimitMib must be >= 32.');
        }
        if ($memorySpikeMib < 1 || $memorySpikeMib >= $memoryLimitMib) {
            throw new InvalidArgumentException('CollectorBufferPolicy memorySpikeMib must be >= 1 and < memoryLimitMib.');
        }
        if ($retryMaxSeconds < 1) {
            throw new InvalidArgumentException('CollectorBufferPolicy retryMaxSeconds must be >= 1.');
        }
        foreach ($cardinalityDenyList as $key) {
            if ($key === '') {
                throw new InvalidArgumentException('CollectorBufferPolicy cardinalityDenyList entries must be non-empty strings.');
            }
        }
    }

    /**
     * Per-request / unbounded attributes that must never become metric labels —
     * dropped at the collector so a runaway label can't blow up the backend.
     */
    public const DEFAULT_CARDINALITY_DENY_LIST = [
        'http.target',
        'request.id',
        'request_id',
        'session.id',
        'session_id',
        'trace.id',
        'trace_id',
        'url.full',
        'user.id',
        'user_id',
    ];
}
