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

        $config = [
            'extensions' => [
                self::STORAGE_EXTENSION => [
                    'directory' => $policy->storageDir,
                    'timeout' => '10s',
                ],
            ],
            'receivers' => [
                'otlp' => [
                    'protocols' => [
                        'grpc' => ['endpoint' => $receiverHost . ':4317'],
                        'http' => ['endpoint' => $receiverHost . ':4318'],
                    ],
                ],
            ],
            'processors' => $this->processors($policy),
            'exporters' => [
                $exporterKey => $this->exporterSettings($exporter->settings),
            ],
            'service' => [
                'extensions' => [self::STORAGE_EXTENSION],
                'pipelines' => $this->pipelines($sink, $exporterKey, $policy),
            ],
        ];

        return new CollectorConfig($config);
    }

    /**
     * @return array<string, mixed>
     */
    private function processors(CollectorBufferPolicy $policy): array
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
    private function pipelines(MetricsSinkInterface $sink, string $exporterKey, CollectorBufferPolicy $policy): array
    {
        $processorChain = ['memory_limiter'];
        if ($policy->cardinalityDenyList !== []) {
            $processorChain[] = 'attributes/cardinality';
        }
        $processorChain[] = 'batch';

        $pipelines = [];
        foreach ($sink->signals() as $signal) {
            $pipelines[$signal->value] = [
                'receivers' => ['otlp'],
                'processors' => $signal === TelemetrySignal::Metrics
                    ? $processorChain
                    // cardinality deletion is metric-specific; traces/logs only batch.
                    : ['memory_limiter', 'batch'],
                'exporters' => [$exporterKey],
            ];
        }

        return $pipelines;
    }
}
