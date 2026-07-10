<?php

declare(strict_types=1);

namespace Vortos\Observability\Collector;

/**
 * Renders the logs pipeline fragment (Block 16, §3.3) and merges it into an
 * already-built {@see CollectorConfig} (the metrics/traces config), so application
 * logs ride the **existing** collector sidecar — no new infrastructure, only a
 * read-only mount of the container-log directory the operator adds to the sidecar.
 *
 * The pipeline is: filelog receiver (tails Docker json-file container logs, unwraps
 * the Docker envelope, parses the app's structured JSON into attributes) → transform
 * redaction (drops secret-named keys, masks secret-shaped values in body + attributes,
 * before logs leave the host) → optional sampling → batch → a dedicated, disk-buffered
 * OTLP exporter (its own persistent queue so a log backlog can never stall metric/trace
 * delivery).
 *
 * Pure: never performs I/O.
 */
final class LogPipelineBuilder
{
    private const STORAGE_EXTENSION = 'file_storage/vortos';
    private const FILELOG_RECEIVER = 'filelog/vortos';
    private const REDACTION_PROCESSOR = 'transform/vortos_logs';
    private const SAMPLER_PROCESSOR = 'probabilistic_sampler/vortos_logs';

    /** RFC3339-nano — the timestamp format Docker's json-file driver writes. */
    private const DOCKER_TIME_LAYOUT = '2006-01-02T15:04:05.999999999Z07:00';

    /**
     * @param array{type: string, settings: array<string, mixed>} $logsExporter the selected sink's
     *              exporter type + settings (endpoint/headers/tls), reused verbatim so logs land at the
     *              same backend as metrics/traces. A dedicated queue is layered on below.
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
            // Persistent read-offset checkpoint: survives collector restarts so no line is
            // re-shipped or skipped across a redeploy.
            'storage' => self::STORAGE_EXTENSION,
            'start_at' => $logConfig->startAt,
            'operators' => [
                // 1. Parse the Docker json-file envelope ({"log":"…","stream":"…","time":"…"}) and
                //    lift its RFC3339-nano timestamp onto the record so event time is preserved.
                [
                    'type' => 'json_parser',
                    'parse_from' => 'body',
                    'timestamp' => [
                        'parse_from' => 'attributes.time',
                        'layout_type' => 'gotime',
                        'layout' => self::DOCKER_TIME_LAYOUT,
                    ],
                ],
                // 2. Promote the real application line to the record body.
                [
                    'type' => 'move',
                    'from' => 'attributes.log',
                    'to' => 'body',
                ],
                // 3. Parse the application's structured JSON body into attributes so fields are
                //    queryable. A non-JSON line (early boot noise) is kept as-is, not dropped.
                [
                    'type' => 'json_parser',
                    'parse_from' => 'body',
                    'parse_to' => 'attributes',
                    'on_error' => 'send_quiet',
                ],
            ],
        ];

        $config['processors'][self::REDACTION_PROCESSOR] = $logConfig->redaction->toProcessorConfig();

        // memory_limiter first; then redaction (drop secret keys + mask secret values); then
        // optional sampling; then batch.
        $processorChain = ['memory_limiter', self::REDACTION_PROCESSOR];
        if ($logConfig->sampleRatio < 1.0) {
            $config['processors'][self::SAMPLER_PROCESSOR] = [
                'sampling_percentage' => round($logConfig->sampleRatio * 100, 4),
            ];
            $processorChain[] = self::SAMPLER_PROCESSOR;
        }
        $processorChain[] = 'batch';

        $exporterKey = $logsExporter['type'] . '/vortos_logs';
        $settings = $logsExporter['settings'];
        ksort($settings);
        // Dedicated disk-backed queue for logs, isolated from the metrics/traces exporter's queue
        // so a log spike or backend blip drains independently and never blocks telemetry delivery.
        $settings['sending_queue'] = [
            'enabled' => true,
            'storage' => self::STORAGE_EXTENSION,
        ];
        $settings['retry_on_failure'] = [
            'enabled' => true,
            'max_elapsed_time' => '300s',
        ];
        $config['exporters'][$exporterKey] = $settings;

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
