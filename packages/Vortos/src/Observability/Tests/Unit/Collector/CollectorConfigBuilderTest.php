<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Collector;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Collector\CollectorBufferPolicy;
use Vortos\Observability\Collector\CollectorConfigBuilder;
use Vortos\Observability\Driver\GrafanaOtlp\GrafanaOtlpMetricsSink;
use Vortos\Observability\Driver\Null\NullMetricsSink;

final class CollectorConfigBuilderTest extends TestCase
{
    private function build(): array
    {
        $sink = new GrafanaOtlpMetricsSink('collector.example.com');

        return (new CollectorConfigBuilder())->build($sink, new CollectorBufferPolicy())->toArray();
    }

    public function test_receiver_binds_loopback_only(): void
    {
        $config = $this->build();

        self::assertSame('127.0.0.1:4317', $config['receivers']['otlp']['protocols']['grpc']['endpoint']);
        self::assertSame('127.0.0.1:4318', $config['receivers']['otlp']['protocols']['http']['endpoint']);
    }

    public function test_memory_limiter_and_batch_present(): void
    {
        $config = $this->build();

        self::assertArrayHasKey('memory_limiter', $config['processors']);
        self::assertArrayHasKey('batch', $config['processors']);
    }

    public function test_cardinality_processor_has_delete_actions(): void
    {
        $config = $this->build();

        self::assertArrayHasKey('attributes/cardinality', $config['processors']);
        $actions = $config['processors']['attributes/cardinality']['actions'];
        self::assertNotEmpty($actions);
        foreach ($actions as $action) {
            self::assertSame('delete', $action['action']);
        }
    }

    public function test_exporter_has_retry_and_sending_queue_with_file_storage(): void
    {
        $config = $this->build();

        $exporterKey = array_key_first($config['exporters']);
        $exporter = $config['exporters'][$exporterKey];

        self::assertTrue($exporter['retry_on_failure']['enabled']);
        self::assertTrue($exporter['sending_queue']['enabled']);
        self::assertSame('file_storage/vortos', $exporter['sending_queue']['storage']);
    }

    public function test_file_storage_extension_declared_and_referenced(): void
    {
        $config = $this->build();

        self::assertArrayHasKey('file_storage/vortos', $config['extensions']);
        self::assertContains('file_storage/vortos', $config['service']['extensions']);
    }

    public function test_pipelines_match_declared_signals(): void
    {
        $config = $this->build();

        // grafana carries metrics, traces, logs.
        self::assertArrayHasKey('metrics', $config['service']['pipelines']);
        self::assertArrayHasKey('traces', $config['service']['pipelines']);
        self::assertArrayHasKey('logs', $config['service']['pipelines']);
    }

    public function test_metrics_pipeline_includes_cardinality_processor(): void
    {
        $config = $this->build();

        self::assertContains('attributes/cardinality', $config['service']['pipelines']['metrics']['processors']);
        // traces/logs do not get cardinality deletion.
        self::assertNotContains('attributes/cardinality', $config['service']['pipelines']['traces']['processors']);
    }

    public function test_memory_limiter_is_first_processor_in_pipeline(): void
    {
        $config = $this->build();

        self::assertSame('memory_limiter', $config['service']['pipelines']['metrics']['processors'][0]);
    }

    public function test_null_sink_produces_no_pipelines(): void
    {
        $config = (new CollectorConfigBuilder())->build(new NullMetricsSink(), new CollectorBufferPolicy())->toArray();

        self::assertSame([], $config['service']['pipelines']);
    }

    public function test_deterministic_output(): void
    {
        $sink = new GrafanaOtlpMetricsSink('h');
        $builder = new CollectorConfigBuilder();
        $policy = new CollectorBufferPolicy();

        self::assertSame(
            $builder->build($sink, $policy)->toArray(),
            $builder->build($sink, $policy)->toArray(),
        );
    }

    public function test_no_cardinality_processor_when_deny_list_empty(): void
    {
        $sink = new GrafanaOtlpMetricsSink('h');
        $policy = new CollectorBufferPolicy(cardinalityDenyList: []);
        $config = (new CollectorConfigBuilder())->build($sink, $policy)->toArray();

        self::assertArrayNotHasKey('attributes/cardinality', $config['processors']);
        self::assertNotContains('attributes/cardinality', $config['service']['pipelines']['metrics']['processors']);
    }
}
