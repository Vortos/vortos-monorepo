<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

/**
 * Renders the logs pipeline fragment (Block 16, §3.3) and merges it into an
 * already-built {@see CollectorConfig} (the Block 15 metrics/traces config),
 * so logs ride the **existing** sidecar — no new infra. Pure: never performs I/O.
 */
final class LogPipelineBuilder
{
    private const STORAGE_EXTENSION = 'file_storage/vortos';
    private const FILELOG_RECEIVER = 'filelog/vortos';

    /**
     * @param array{type: string, settings: array<string, mixed>} $logsExporter
     */
    public function merge(CollectorConfig $base, LogPipelineConfig $logConfig, array $logsExporter): CollectorConfig
    {
        $config = $base->toArray();

        $config['extensions'][self::STORAGE_EXTENSION] ??= [
            'directory' => $logConfig->storageDir,
            'timeout' => '10s',
        ];

        $config['receivers'][self::FILELOG_RECEIVER] = [
            'include' => $logConfig->includePaths,
            'storage' => self::STORAGE_EXTENSION,
            'start_at' => 'beginning',
        ];

        $config['processors']['redaction/vortos'] = $logConfig->redaction->toProcessorConfig();

        if ($logConfig->infoSampleRatio < 1.0) {
            $config['processors']['probabilistic_sampler/vortos_logs'] = [
                'sampling_percentage' => round($logConfig->infoSampleRatio * 100, 4),
            ];
        }

        $exporterKey = $logsExporter['type'] . '/vortos_logs';
        $settings = $logsExporter['settings'];
        ksort($settings);
        $settings['sending_queue'] = [
            'enabled' => true,
            'storage' => self::STORAGE_EXTENSION,
        ];
        $settings['retry_on_failure'] = [
            'enabled' => true,
            'max_elapsed_time' => '300s',
        ];
        $config['exporters'][$exporterKey] = $settings;

        $processorChain = ['memory_limiter', 'redaction/vortos'];
        if ($logConfig->infoSampleRatio < 1.0) {
            $processorChain[] = 'probabilistic_sampler/vortos_logs';
        }
        $processorChain[] = 'batch';

        $config['service']['extensions'] = array_values(array_unique([
            ...($config['service']['extensions'] ?? []),
            self::STORAGE_EXTENSION,
        ]));

        $config['service']['pipelines']['logs'] = [
            'receivers' => [self::FILELOG_RECEIVER],
            'processors' => $processorChain,
            'exporters' => [$exporterKey],
        ];

        return new CollectorConfig($config);
    }
}
