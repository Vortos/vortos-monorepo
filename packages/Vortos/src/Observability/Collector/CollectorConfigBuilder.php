<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

use Vortos\Observability\Sink\MetricsSinkInterface;
use Vortos\Observability\Sink\TelemetrySignal;

/**
 * Pure builder: turns the selected {@see MetricsSinkInterface} + a
 * {@see CollectorBufferPolicy} into a deterministic {@see CollectorConfig}.
 *
 * Design invariants enforced here (not left to the operator):
 *  - the OTLP receiver binds **loopback only** (`127.0.0.1`) — the app emits locally,
 *    the receiver is never exposed publicly;
 *  - `memory_limiter` + `batch` are always present so the collector cannot OOM the
 *    host and batches are bounded;
 *  - the selected sink's exporter gets `retry_on_failure` + a `sending_queue` backed
 *    by the `file_storage` extension → disk buffering across a backend blip;
 *  - high-cardinality attributes are deleted before export.
 *
 * No backend name appears here — only the sink's own `exporterConfig()` carries that.
 */
final class CollectorConfigBuilder
{
    public const DEFAULT_RECEIVER_HOST = '127.0.0.1';
    private const STORAGE_EXTENSION = 'file_storage/vortos';

    /**
     * @param string $receiverHost interface the OTLP receiver binds to. Defaults to loopback
     *                             (single-container / sidecar sharing the app's netns). Set to a
     *                             private-network address (e.g. 0.0.0.0 behind an isolated Docker
     *                             network) when a separate worker container must emit to a shared
     *                             collector — see the multi-container topology recipe (P3-2).
     */
    public function build(
        MetricsSinkInterface $sink,
        CollectorBufferPolicy $policy,
        string $receiverHost = self::DEFAULT_RECEIVER_HOST,
    ): CollectorConfig {
        $exporter = $sink->exporterConfig();
        $exporterKey = $exporter->type . '/' . $sink->name();

        // Host/container scrapers only make sense when the sink actually carries metrics
        // (the null sink emits no pipelines) — gate them so we never declare an unused receiver.
        $hasMetrics = in_array(TelemetrySignal::Metrics, $sink->signals(), true);
        $emitHost = $policy->hostMetrics && $hasMetrics;
        $emitContainer = $policy->containerMetrics && $hasMetrics;
        $promote = $emitHost || $emitContainer;

        $receivers = [
            'otlp' => [
                'protocols' => [
                    'grpc' => ['endpoint' => $receiverHost . ':4317'],
                    'http' => ['endpoint' => $receiverHost . ':4318'],
                ],
            ],
        ];
        if ($emitHost) {
            $receivers['hostmetrics'] = $this->hostMetricsReceiver();
        }
        if ($emitContainer) {
            $receivers['docker_stats'] = $this->dockerStatsReceiver($policy);
        }

        $config = [
            'extensions' => [
                self::STORAGE_EXTENSION => [
                    'directory' => $policy->storageDir,
                    'timeout' => '10s',
                ],
            ],
            'receivers' => $receivers,
            'processors' => $this->processors($policy, $promote),
            'exporters' => [
                $exporterKey => $this->exporterSettings($exporter->settings),
            ],
            'service' => [
                'extensions' => [self::STORAGE_EXTENSION],
                'pipelines' => $this->pipelines($sink, $exporterKey, $policy, $emitHost, $emitContainer, $promote),
            ],
        ];

        return new CollectorConfig($config);
    }

    /**
     * @return array<string, mixed>
     */
    private function processors(CollectorBufferPolicy $policy, bool $promote): array
    {
        $processors = [
            // memory_limiter MUST be first in every pipeline; bounds collector RAM.
            'memory_limiter' => [
                'check_interval' => '1s',
                'limit_mib' => $policy->memoryLimitMib,
                'spike_limit_mib' => $policy->memorySpikeMib,
            ],
            'batch' => [
                'send_batch_size' => 8192,
                'send_batch_max_size' => 16384,
                'timeout' => '5s',
            ],
        ];

        if ($promote) {
            $processors[self::PROMOTE_PROCESSOR] = $this->promoteProcessor();
        }

        if ($policy->cardinalityDenyList !== []) {
            $actions = [];
            $denied = $policy->cardinalityDenyList;
            sort($denied);
            foreach ($denied as $key) {
                $actions[] = ['key' => $key, 'action' => 'delete'];
            }
            $processors['attributes/cardinality'] = ['actions' => $actions];
        }

        return $processors;
    }

    private const PROMOTE_PROCESSOR = 'transform/promote';

    /**
     * hostmetrics receiver: host CPU/load/memory/disk/network/paging/filesystem. Reads the host's
     * world-readable /proc etc. via the /hostfs bind mount (root_path), so it works even as the
     * collector's non-root uid. Filesystem excludes drop pseudo/overlay mounts that would otherwise
     * flood cardinality and error on a containerized view.
     *
     * @return array<string, mixed>
     */
    private function hostMetricsReceiver(): array
    {
        return [
            'root_path' => self::HOSTFS_ROOT,
            'collection_interval' => self::SCRAPE_INTERVAL,
            'scrapers' => [
                'cpu' => ['metrics' => ['system.cpu.utilization' => ['enabled' => true]]],
                'load' => [],
                'memory' => ['metrics' => ['system.memory.utilization' => ['enabled' => true]]],
                'disk' => [],
                'network' => [],
                'paging' => [],
                'filesystem' => [
                    'exclude_mount_points' => [
                        'match_type' => 'regexp',
                        'mount_points' => ['/hostfs/(dev|proc|sys|run|var/lib/docker|var/lib/containers).*'],
                    ],
                    'exclude_fs_types' => [
                        'match_type' => 'strict',
                        'fs_types' => ['overlay', 'tmpfs', 'devtmpfs', 'squashfs', 'autofs', 'mqueue', 'nsfs', 'proc', 'sysfs', 'tracefs', 'cgroup', 'cgroup2'],
                    ],
                ],
            ],
        ];
    }

    /**
     * docker_stats receiver: per-container CPU/memory. Talks the Docker API over the endpoint in the
     * policy (defaults to the least-privilege socket-proxy — no raw docker.sock mount). api_version is
     * pinned because the receiver default (1.25) is refused by modern daemons.
     *
     * @return array<string, mixed>
     */
    private function dockerStatsReceiver(CollectorBufferPolicy $policy): array
    {
        return [
            'endpoint' => $policy->containerStatsEndpoint,
            'api_version' => $policy->dockerApiVersion,
            'collection_interval' => self::SCRAPE_INTERVAL,
            'metrics' => [
                'container.cpu.utilization' => ['enabled' => true],
                'container.memory.percent' => ['enabled' => true],
            ],
        ];
    }

    /**
     * Promote resource attributes onto datapoints so they become queryable Prometheus labels:
     * docker_stats keeps container.name/image on the resource (not the datapoint), and hostmetrics
     * keeps host.name — without this the backend can't break metrics down per-container/host.
     *
     * @return array<string, mixed>
     */
    private function promoteProcessor(): array
    {
        return [
            'metric_statements' => [
                [
                    'context' => 'datapoint',
                    'statements' => [
                        'set(attributes["container_name"], resource.attributes["container.name"]) where resource.attributes["container.name"] != nil',
                        'set(attributes["image"], resource.attributes["container.image.name"]) where resource.attributes["container.image.name"] != nil',
                        'set(attributes["host"], resource.attributes["host.name"]) where resource.attributes["host.name"] != nil',
                    ],
                ],
            ],
        ];
    }

    private const HOSTFS_ROOT = '/hostfs';
    private const SCRAPE_INTERVAL = '30s';

    /**
     * @param array<string, scalar|array<string, scalar|null>|null> $settings
     * @return array<string, mixed>
     */
    private function exporterSettings(array $settings): array
    {
        ksort($settings);

        // Disk-buffered, retrying delivery — the heart of "survive a network blip".
        $settings['retry_on_failure'] = [
            'enabled' => true,
            'max_elapsed_time' => '300s',
        ];
        $settings['sending_queue'] = [
            'enabled' => true,
            'storage' => self::STORAGE_EXTENSION,
        ];

        return $settings;
    }

    /**
     * @return array<string, array{receivers:list<string>, processors:list<string>, exporters:list<string>}>
     */
    private function pipelines(
        MetricsSinkInterface $sink,
        string $exporterKey,
        CollectorBufferPolicy $policy,
        bool $emitHost,
        bool $emitContainer,
        bool $promote,
    ): array {
        // Metrics processor chain: memory_limiter first, then label promotion (before cardinality
        // deletion so a promoted label can still be dropped if denied), then cardinality, then batch.
        $processorChain = ['memory_limiter'];
        if ($promote) {
            $processorChain[] = self::PROMOTE_PROCESSOR;
        }
        if ($policy->cardinalityDenyList !== []) {
            $processorChain[] = 'attributes/cardinality';
        }
        $processorChain[] = 'batch';

        $metricsReceivers = ['otlp'];
        if ($emitHost) {
            $metricsReceivers[] = 'hostmetrics';
        }
        if ($emitContainer) {
            $metricsReceivers[] = 'docker_stats';
        }

        $pipelines = [];
        foreach ($sink->signals() as $signal) {
            // Logs are NOT emitted here: an `otlp`-receiver logs pipeline has nothing feeding it
            // (the app writes stderr, it does not push OTLP logs), so it would be a permanently
            // dead pipeline. The log pipeline is a filelog-based one grafted on by
            // LogPipelineBuilder::merge() only when log aggregation is enabled.
            if ($signal === TelemetrySignal::Logs) {
                continue;
            }
            $isMetrics = $signal === TelemetrySignal::Metrics;
            $pipelines[$signal->value] = [
                'receivers' => $isMetrics ? $metricsReceivers : ['otlp'],
                'processors' => $isMetrics
                    ? $processorChain
                    // cardinality deletion + promotion are metric-specific; traces only batch.
                    : ['memory_limiter', 'batch'],
                'exporters' => [$exporterKey],
            ];
        }

        return $pipelines;
    }
}
