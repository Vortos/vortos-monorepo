<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Collector\CollectorBufferPolicy;
use Vortos\Observability\Collector\CollectorConfigBuilder;
use Vortos\Observability\Driver\GrafanaOtlp\GrafanaOtlpMetricsSink;
use Vortos\Observability\Sink\OtlpProtocol;

/**
 * Pins the rendered collector config so a change to rendering can never silently alter
 * the deployed sidecar config, and asserts the structural contract the OTel collector
 * requires (receivers + processors + exporters + service.pipelines all present and
 * well-typed).
 */
final class CollectorConfigContractTest extends TestCase
{
    private function pinnedConfig(): array
    {
        $sink = new GrafanaOtlpMetricsSink(
            host: 'collector.example.com',
            protocol: OtlpProtocol::HttpProtobuf,
            port: 443,
            tlsEnabled: true,
            headersEnvRef: 'OBSERVABILITY_GRAFANA_OTLP_HEADERS',
        );

        $policy = new CollectorBufferPolicy(
            storageDir: '/var/lib/otelcol/storage',
            memoryLimitMib: 256,
            memorySpikeMib: 64,
            retryMaxSeconds: 300,
            cardinalityDenyList: ['user.id', 'request.id'],
        );

        return (new CollectorConfigBuilder())->build($sink, $policy)->toArray();
    }

    public function test_structural_contract(): void
    {
        $config = $this->pinnedConfig();

        self::assertArrayHasKey('receivers', $config);
        self::assertArrayHasKey('processors', $config);
        self::assertArrayHasKey('exporters', $config);
        self::assertArrayHasKey('extensions', $config);
        self::assertArrayHasKey('service', $config);
        self::assertArrayHasKey('pipelines', $config['service']);
        self::assertNotEmpty($config['service']['pipelines']);
    }

    public function test_pinned_vector(): void
    {
        self::assertSame([
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
                'attributes/cardinality' => [
                    'actions' => [
                        ['key' => 'request.id', 'action' => 'delete'],
                        ['key' => 'user.id', 'action' => 'delete'],
                    ],
                ],
            ],
            'exporters' => [
                'otlphttp/grafana' => [
                    'endpoint' => 'https://collector.example.com',
                    'headers' => ['Authorization' => '${env:OBSERVABILITY_GRAFANA_OTLP_HEADERS}'],
                    'tls' => ['insecure' => false],
                    'retry_on_failure' => ['enabled' => true, 'max_elapsed_time' => '300s'],
                    'sending_queue' => ['enabled' => true, 'storage' => 'file_storage/vortos'],
                ],
            ],
            'service' => [
                'extensions' => ['file_storage/vortos'],
                'pipelines' => [
                    'metrics' => [
                        'receivers' => ['otlp'],
                        'processors' => ['memory_limiter', 'attributes/cardinality', 'batch'],
                        'exporters' => ['otlphttp/grafana'],
                    ],
                    'traces' => [
                        'receivers' => ['otlp'],
                        'processors' => ['memory_limiter', 'batch'],
                        'exporters' => ['otlphttp/grafana'],
                    ],
                    // No 'logs' pipeline: the base metrics/traces config carries only OTLP-push
                    // signals. Logs are a filelog pipeline added by LogPipelineBuilder::merge().
                ],
            ],
        ], $this->pinnedConfig());
    }

    public function test_yaml_is_parseable_back_into_same_top_level_keys(): void
    {
        $sink = new GrafanaOtlpMetricsSink('collector.example.com');
        $yaml = (new CollectorConfigBuilder())->build($sink, new CollectorBufferPolicy())->toYaml();

        foreach (['extensions:', 'receivers:', 'processors:', 'exporters:', 'service:'] as $key) {
            self::assertStringContainsString($key, $yaml);
        }
    }
}
