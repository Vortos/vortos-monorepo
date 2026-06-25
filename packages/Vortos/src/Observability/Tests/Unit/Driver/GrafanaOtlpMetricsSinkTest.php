<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Unit\Driver;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Driver\GrafanaOtlp\GrafanaOtlpMetricsSink;
use Vortos\Observability\Sink\Capability\SinkCapability;
use Vortos\Observability\Sink\OtlpProtocol;
use Vortos\Observability\Sink\TelemetrySignal;

final class GrafanaOtlpMetricsSinkTest extends TestCase
{
    public function test_name_is_driver_key(): void
    {
        self::assertSame('grafana', (new GrafanaOtlpMetricsSink('h'))->name());
    }

    public function test_carries_all_signals(): void
    {
        $signals = (new GrafanaOtlpMetricsSink('h'))->signals();

        self::assertSame(
            [TelemetrySignal::Metrics, TelemetrySignal::Traces, TelemetrySignal::Logs],
            $signals,
        );
    }

    public function test_declares_off_host_true(): void
    {
        self::assertTrue((new GrafanaOtlpMetricsSink('h'))->capabilities()->supports(SinkCapability::OffHost));
    }

    public function test_http_protocol_uses_otlphttp_exporter(): void
    {
        $exporter = (new GrafanaOtlpMetricsSink('h', OtlpProtocol::HttpProtobuf))->exporterConfig();

        self::assertSame('otlphttp', $exporter->type);
    }

    public function test_grpc_protocol_uses_otlp_exporter(): void
    {
        $exporter = (new GrafanaOtlpMetricsSink('h', OtlpProtocol::Grpc))->exporterConfig();

        self::assertSame('otlp', $exporter->type);
    }

    public function test_exporter_uses_env_placeholder_for_headers_not_plaintext(): void
    {
        $exporter = (new GrafanaOtlpMetricsSink('h', headersEnvRef: 'MY_HEADERS'))->exporterConfig();
        $settings = $exporter->toArray()['settings'];

        self::assertSame('${env:MY_HEADERS}', $settings['headers']['Authorization']);
    }

    public function test_tls_disabled_marks_exporter_insecure(): void
    {
        $exporter = (new GrafanaOtlpMetricsSink('h', tlsEnabled: false))->exporterConfig();

        self::assertTrue($exporter->toArray()['settings']['tls']['insecure']);
        self::assertFalse((new GrafanaOtlpMetricsSink('h', tlsEnabled: false))->capabilities()->supports(SinkCapability::Tls));
    }
}
