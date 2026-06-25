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
            infoSampleRatio: 0.1,
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
        self::assertArrayHasKey('redaction/vortos', $config['processors']);
        self::assertArrayHasKey('probabilistic_sampler/vortos_logs', $config['processors']);
        self::assertArrayHasKey('logs', $config['service']['pipelines']);
        self::assertContains('file_storage/vortos', $config['service']['extensions']);
    }

    public function test_filelog_receiver_pinned(): void
    {
        $config = $this->pinnedLogConfig();

        self::assertSame([
            'include' => ['/var/lib/docker/containers/*/*.log'],
            'storage' => 'file_storage/vortos',
            'start_at' => 'beginning',
        ], $config['receivers']['filelog/vortos']);
    }

    public function test_redaction_processor_pinned(): void
    {
        $config = $this->pinnedLogConfig();
        $redaction = $config['processors']['redaction/vortos'];

        self::assertFalse($redaction['allow_all_keys']);
        self::assertNotEmpty($redaction['blocked_values']);
        self::assertNotEmpty($redaction['allowed_keys']);
        self::assertSame('redacted', $redaction['summary']);
    }

    public function test_logs_pipeline_pinned(): void
    {
        $config = $this->pinnedLogConfig();

        self::assertSame([
            'receivers' => ['filelog/vortos'],
            'processors' => ['memory_limiter', 'redaction/vortos', 'probabilistic_sampler/vortos_logs', 'batch'],
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
