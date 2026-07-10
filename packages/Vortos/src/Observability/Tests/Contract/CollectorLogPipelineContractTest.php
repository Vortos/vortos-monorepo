<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Collector\CollectorConfig;
use Vortos\Observability\Collector\LogPipelineBuilder;
use Vortos\Observability\Collector\LogPipelineConfig;
use Vortos\Observability\Collector\LogRedactionPolicy;

/**
 * Pins the rendered log pipeline config so a change to rendering can never silently
 * alter the deployed sidecar config. Mirrors CollectorConfigContractTest for metrics.
 */
final class CollectorLogPipelineContractTest extends TestCase
{
    private function baseConfig(): CollectorConfig
    {
        return new CollectorConfig([
            'extensions' => [
                'file_storage/vortos' => [
                    'directory' => '/var/lib/otelcol/storage',
                    'timeout' => '10s',
                ],
            ],
            'receivers' => [
                'otlp' => [
                    'protocols' => [
                        'grpc' => ['endpoint' => '127.0.0.1:4317'],
                        'http' => ['endpoint' => '127.0.0.1:4318'],
                    ],
                ],
            ],
            'processors' => [
                'memory_limiter' => [
                    'check_interval' => '1s',
                    'limit_mib' => 256,
                    'spike_limit_mib' => 64,
                ],
                'batch' => [
                    'send_batch_size' => 8192,
                    'send_batch_max_size' => 16384,
                    'timeout' => '5s',
                ],
            ],
            'exporters' => [],
            'service' => [
                'extensions' => ['file_storage/vortos'],
                'pipelines' => [
                    'metrics' => [
                        'receivers' => ['otlp'],
                        'processors' => ['memory_limiter', 'batch'],
                        'exporters' => [],
                    ],
                ],
            ],
        ]);
    }

    private function pinnedLogConfig(): array
    {
        $builder = new LogPipelineBuilder();
        $logConfig = new LogPipelineConfig(
            includePaths: ['/var/lib/docker/containers/*/*.log'],
            redaction: new LogRedactionPolicy(),
            sampleRatio: 0.1,
            storageDir: '/var/lib/otelcol/storage',
        );

        return $builder->merge($this->baseConfig(), $logConfig, [
            'type' => 'otlphttp',
            'settings' => ['endpoint' => 'https://logs.example.com'],
        ])->toArray();
    }

    public function test_structural_contract(): void
    {
        $config = $this->pinnedLogConfig();

        self::assertArrayHasKey('filelog/vortos', $config['receivers']);
        self::assertArrayHasKey('transform/vortos_logs', $config['processors']);
        self::assertArrayHasKey('probabilistic_sampler/vortos_logs', $config['processors']);
        self::assertArrayHasKey('logs', $config['service']['pipelines']);
        self::assertContains('file_storage/vortos', $config['service']['extensions']);
    }

    public function test_filelog_receiver_pinned(): void
    {
        $config = $this->pinnedLogConfig();
        $receiver = $config['receivers']['filelog/vortos'];

        self::assertSame(['/var/lib/docker/containers/*/*.log'], $receiver['include']);
        self::assertSame('file_storage/vortos', $receiver['storage']);
        self::assertSame('end', $receiver['start_at']);
        // Envelope parse → promote app line to body → parse app JSON into attributes.
        self::assertSame('json_parser', $receiver['operators'][0]['type']);
        self::assertSame('move', $receiver['operators'][1]['type']);
        self::assertSame('json_parser', $receiver['operators'][2]['type']);
    }

    public function test_redaction_processor_pinned(): void
    {
        $config = $this->pinnedLogConfig();
        $redaction = $config['processors']['transform/vortos_logs'];

        // Rendered as a logs-capable `transform` processor (the `redaction` processor of the
        // pinned collector does not support the logs signal).
        self::assertSame('ignore', $redaction['error_mode']);
        self::assertSame('log', $redaction['log_statements'][0]['context']);

        $joined = implode("\n", $redaction['log_statements'][0]['statements']);
        // Drops secret-named keys and masks secret values in body + attributes.
        self::assertStringContainsString('delete_matching_keys(attributes,', $joined);
        self::assertStringContainsString('replace_pattern(body,', $joined);
        self::assertStringContainsString('replace_all_patterns(attributes, "value",', $joined);
        // OTTL regex backslashes are doubled (a single backslash would crash the collector).
        self::assertStringContainsString('\\\\b', $joined);
    }

    public function test_logs_pipeline_pinned(): void
    {
        $config = $this->pinnedLogConfig();

        self::assertSame([
            'receivers' => ['filelog/vortos'],
            'processors' => ['memory_limiter', 'transform/vortos_logs', 'probabilistic_sampler/vortos_logs', 'batch'],
            'exporters' => ['otlphttp/vortos_logs'],
        ], $config['service']['pipelines']['logs']);
    }

    public function test_logs_exporter_has_persistent_queue(): void
    {
        $config = $this->pinnedLogConfig();
        $exporter = $config['exporters']['otlphttp/vortos_logs'];

        self::assertTrue($exporter['sending_queue']['enabled']);
        self::assertSame('file_storage/vortos', $exporter['sending_queue']['storage']);
        self::assertTrue($exporter['retry_on_failure']['enabled']);
    }

    public function test_sampling_processor_percentage_matches_ratio(): void
    {
        $config = $this->pinnedLogConfig();

        self::assertSame(10.0, $config['processors']['probabilistic_sampler/vortos_logs']['sampling_percentage']);
    }
}
