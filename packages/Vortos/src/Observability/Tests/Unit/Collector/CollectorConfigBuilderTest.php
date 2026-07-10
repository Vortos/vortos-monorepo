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

        // The base builder emits metrics + traces (both OTLP-push signals). The logs pipeline is
        // NOT emitted here: an otlp-receiver logs pipeline would have nothing feeding it. Logs are
        // grafted on by LogPipelineBuilder::merge() (a filelog pipeline) only when enabled.
        self::assertArrayHasKey('metrics', $config['service']['pipelines']);
        self::assertArrayHasKey('traces', $config['service']['pipelines']);
        self::assertArrayNotHasKey('logs', $config['service']['pipelines']);
    }

    public function test_metrics_pipeline_includes_cardinality_processor(): void
    {
        $config = $this->build();

        self::assertContains('attributes/cardinality', $config['service']['pipelines']['metrics']['processors']);
        // traces do not get cardinality deletion.
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

    // ── Host + container metrics (opt-in) ──────────────────────────────────────────

    private function buildWith(CollectorBufferPolicy $policy): array
    {
        return (new CollectorConfigBuilder())->build(new GrafanaOtlpMetricsSink('h'), $policy)->toArray();
    }

    public function test_no_host_or_container_receivers_by_default(): void
    {
        $config = $this->build();

        self::assertArrayNotHasKey('hostmetrics', $config['receivers']);
        self::assertArrayNotHasKey('docker_stats', $config['receivers']);
        self::assertArrayNotHasKey('transform/promote', $config['processors']);
        self::assertSame(['otlp'], $config['service']['pipelines']['metrics']['receivers']);
    }

    public function test_host_metrics_adds_receiver_and_wires_metrics_pipeline_only(): void
    {
        $config = $this->buildWith(new CollectorBufferPolicy(hostMetrics: true));

        self::assertArrayHasKey('hostmetrics', $config['receivers']);
        self::assertSame('/hostfs', $config['receivers']['hostmetrics']['root_path']);
        self::assertContains('hostmetrics', $config['service']['pipelines']['metrics']['receivers']);
        // traces/logs stay OTLP-only.
        self::assertSame(['otlp'], $config['service']['pipelines']['traces']['receivers']);
    }

    public function test_container_metrics_adds_docker_stats_with_string_api_version(): void
    {
        $config = $this->buildWith(new CollectorBufferPolicy(containerMetrics: true));

        self::assertArrayHasKey('docker_stats', $config['receivers']);
        self::assertSame('tcp://docker-socket-proxy:2375', $config['receivers']['docker_stats']['endpoint']);
        self::assertSame('1.44', $config['receivers']['docker_stats']['api_version']);
        self::assertContains('docker_stats', $config['service']['pipelines']['metrics']['receivers']);
    }

    public function test_promote_processor_added_and_ordered_before_cardinality(): void
    {
        $config = $this->buildWith(new CollectorBufferPolicy(hostMetrics: true, containerMetrics: true));

        self::assertArrayHasKey('transform/promote', $config['processors']);
        $chain = $config['service']['pipelines']['metrics']['processors'];
        self::assertSame('memory_limiter', $chain[0]);
        self::assertLessThan(
            array_search('attributes/cardinality', $chain, true),
            array_search('transform/promote', $chain, true),
        );
        self::assertSame('batch', $chain[array_key_last($chain)]);
    }

    public function test_host_container_flags_ignored_when_sink_has_no_metrics(): void
    {
        // The null sink emits no pipelines — scrapers would be unused, so they must not be declared.
        $config = (new CollectorConfigBuilder())
            ->build(new NullMetricsSink(), new CollectorBufferPolicy(hostMetrics: true, containerMetrics: true))
            ->toArray();

        self::assertArrayNotHasKey('hostmetrics', $config['receivers']);
        self::assertArrayNotHasKey('docker_stats', $config['receivers']);
        self::assertSame([], $config['service']['pipelines']);
    }

    public function test_api_version_renders_as_quoted_string_in_yaml(): void
    {
        $yaml = (new CollectorConfigBuilder())
            ->build(new GrafanaOtlpMetricsSink('h'), new CollectorBufferPolicy(containerMetrics: true))
            ->toYaml();

        // Must stay a string; an unquoted 1.44 would parse as a float and the receiver rejects it.
        self::assertStringContainsString('api_version: "1.44"', $yaml);
    }
}
