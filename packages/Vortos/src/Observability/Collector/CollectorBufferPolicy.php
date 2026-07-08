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
     * @param bool              $hostMetrics       Scrape host CPU/memory/load/disk/network/paging/filesystem
     *                                             (adds the `hostmetrics` receiver; the collector needs the host
     *                                             root bind-mounted at /hostfs — the generated compose fragment
     *                                             does this when enabled).
     * @param bool              $containerMetrics  Scrape per-container CPU/memory/network via the `docker_stats`
     *                                             receiver over {@see $containerStatsEndpoint}.
     * @param string            $containerStatsEndpoint Docker API endpoint for docker_stats. Defaults to the
     *                                             least-privilege socket-proxy the baseline stack already ships
     *                                             (no raw docker.sock mount into the collector). Use
     *                                             `unix:///var/run/docker.sock` only if no proxy is present.
     * @param string            $dockerApiVersion  Docker API version docker_stats negotiates. Must be a string;
     *                                             the receiver default (1.25) is rejected by modern daemons
     *                                             ("client version too old"), so this defaults to a supported one.
     */
    public function __construct(
        public string $storageDir = '/var/lib/otelcol/storage',
        public int $memoryLimitMib = 256,
        public int $memorySpikeMib = 64,
        public int $retryMaxSeconds = 300,
        public array $cardinalityDenyList = self::DEFAULT_CARDINALITY_DENY_LIST,
        public bool $hostMetrics = false,
        public bool $containerMetrics = false,
        public string $containerStatsEndpoint = self::DEFAULT_CONTAINER_STATS_ENDPOINT,
        public string $dockerApiVersion = self::DEFAULT_DOCKER_API_VERSION,
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
        if ($containerMetrics && $containerStatsEndpoint === '') {
            throw new InvalidArgumentException('CollectorBufferPolicy containerStatsEndpoint must be non-empty when containerMetrics is enabled.');
        }
        if ($containerMetrics && $dockerApiVersion === '') {
            throw new InvalidArgumentException('CollectorBufferPolicy dockerApiVersion must be non-empty when containerMetrics is enabled.');
        }
    }

    /** The baseline stack's least-privilege Docker API proxy (tecnativa docker-socket-proxy on vortos-net). */
    public const DEFAULT_CONTAINER_STATS_ENDPOINT = 'tcp://docker-socket-proxy:2375';

    /** A Docker API version modern daemons accept; the docker_stats receiver default (1.25) is refused. */
    public const DEFAULT_DOCKER_API_VERSION = '1.44';

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
