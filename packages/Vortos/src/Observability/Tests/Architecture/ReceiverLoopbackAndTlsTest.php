<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Collector\CollectorBufferPolicy;
use Vortos\Observability\Collector\CollectorConfigBuilder;
use Vortos\Observability\Driver\GrafanaOtlp\GrafanaOtlpMetricsSink;

/**
 * Security invariants of the generated collector config: the OTLP receiver is only
 * reachable on loopback (never a public interface), and an off-host exporter is never
 * rendered insecure when the sink declares TLS.
 */
final class ReceiverLoopbackAndTlsTest extends TestCase
{
    public function test_receiver_endpoints_are_loopback(): void
    {
        $config = (new CollectorConfigBuilder())
            ->build(new GrafanaOtlpMetricsSink('collector.example.com'), new CollectorBufferPolicy())
            ->toArray();

        $protocols = $config['receivers']['otlp']['protocols'];
        foreach ([$protocols['grpc']['endpoint'], $protocols['http']['endpoint']] as $endpoint) {
            self::assertStringStartsWith('127.0.0.1:', $endpoint, 'OTLP receiver must bind loopback only.');
        }
    }

    public function test_tls_sink_does_not_render_insecure_exporter(): void
    {
        $config = (new CollectorConfigBuilder())
            ->build(new GrafanaOtlpMetricsSink('collector.example.com', tlsEnabled: true), new CollectorBufferPolicy())
            ->toArray();

        $exporterKey = array_key_first($config['exporters']);
        self::assertFalse($config['exporters'][$exporterKey]['tls']['insecure']);
    }
}
