<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Collector\CollectorConfig;
use Vortos\Observability\Collector\LogPipelineBuilder;
use Vortos\Observability\Collector\LogPipelineConfig;
use Vortos\Observability\Collector\LogRedactionPolicy;

/**
 * Verifies the durability invariants of the log aggregation pipeline:
 * persistent queue + file storage + start_at=beginning guarantees
 * no loss and no duplication across collector restarts.
 *
 * These are config-level invariants — if any is violated, the deployed
 * collector will silently drop logs on restart.
 */
final class LogAggregationDurabilityTest extends TestCase
{
    private function mergedConfig(LogPipelineConfig $logConfig): array
    {
        $base = new CollectorConfig([
            'extensions' => [],
            'receivers' => ['otlp' => ['protocols' => ['grpc' => ['endpoint' => '127.0.0.1:4317']]]],
            'processors' => ['memory_limiter' => [], 'batch' => []],
            'exporters' => [],
            'service' => ['extensions' => [], 'pipelines' => []],
        ]);

        return (new LogPipelineBuilder())->merge($base, $logConfig, [
            'type' => 'otlphttp',
            'settings' => ['endpoint' => 'https://logs.example.com'],
        ])->toArray();
    }

    public function test_filelog_receiver_uses_persistent_storage(): void
    {
        $config = $this->mergedConfig(new LogPipelineConfig(['/var/log/*.json']));

        self::assertSame(
            'file_storage/vortos',
            $config['receivers']['filelog/vortos']['storage'],
            'filelog receiver must use persistent file storage to track offsets across restarts',
        );
    }

    public function test_filelog_receiver_starts_at_beginning(): void
    {
        $config = $this->mergedConfig(new LogPipelineConfig(['/var/log/*.json']));

        self::assertSame(
            'beginning',
            $config['receivers']['filelog/vortos']['start_at'],
            'filelog must start at beginning — combined with persistent storage, this ensures no logs are missed on first deploy',
        );
    }

    public function test_exporter_has_persistent_sending_queue(): void
    {
        $config = $this->mergedConfig(new LogPipelineConfig(['/var/log/*.json']));

        $exporterKey = 'otlphttp/vortos_logs';
        $exporter = $config['exporters'][$exporterKey];

        self::assertTrue($exporter['sending_queue']['enabled'], 'Sending queue must be enabled for durability');
        self::assertSame(
            'file_storage/vortos',
            $exporter['sending_queue']['storage'],
            'Sending queue must use persistent file storage to survive restarts',
        );
    }

    public function test_exporter_has_retry_on_failure(): void
    {
        $config = $this->mergedConfig(new LogPipelineConfig(['/var/log/*.json']));

        $exporter = $config['exporters']['otlphttp/vortos_logs'];

        self::assertTrue($exporter['retry_on_failure']['enabled'], 'Retry on failure must be enabled');
        self::assertSame('300s', $exporter['retry_on_failure']['max_elapsed_time']);
    }

    public function test_file_storage_extension_registered_in_service(): void
    {
        $config = $this->mergedConfig(new LogPipelineConfig(['/var/log/*.json']));

        self::assertContains(
            'file_storage/vortos',
            $config['service']['extensions'],
            'file_storage extension must be registered in service.extensions',
        );
    }

    public function test_durability_invariants_hold_across_different_configs(): void
    {
        $configs = [
            new LogPipelineConfig(['/var/log/*.json']),
            new LogPipelineConfig(['/var/log/*.json', '/tmp/app.log'], infoSampleRatio: 0.5),
            new LogPipelineConfig(['/opt/logs/*.log'], redaction: new LogRedactionPolicy([], []), infoSampleRatio: 1.0),
        ];

        foreach ($configs as $i => $logConfig) {
            $config = $this->mergedConfig($logConfig);

            self::assertSame('file_storage/vortos', $config['receivers']['filelog/vortos']['storage'], "Config $i: filelog storage");
            self::assertSame('beginning', $config['receivers']['filelog/vortos']['start_at'], "Config $i: start_at");
            self::assertTrue($config['exporters']['otlphttp/vortos_logs']['sending_queue']['enabled'], "Config $i: sending queue");
            self::assertTrue($config['exporters']['otlphttp/vortos_logs']['retry_on_failure']['enabled'], "Config $i: retry");
        }
    }
}
