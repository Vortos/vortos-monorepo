<?php

declare(strict_types=1);

namespace Vortos\Observability\Driver\GrafanaOtlp;

use Vortos\Observability\Sink\Capability\SinkCapability;
use Vortos\Observability\Sink\ExporterConfig;
use Vortos\Observability\Sink\MetricsSinkInterface;
use Vortos\Observability\Sink\OtlpProtocol;
use Vortos\Observability\Sink\SinkEndpoint;
use Vortos\Observability\Sink\TelemetrySignal;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Default metrics/traces/logs sink: an OTLP endpoint off the app host (Grafana Cloud
 * OTLP gateway, or a self-hosted LGTM stack on a second box). This is the *only*
 * place this backend's name appears — the collector exporter it renders is the §12.4
 * swap point; switching to another backend selects a different driver, never an app
 * code change.
 *
 * Auth headers are referenced via an `${env:...}` placeholder so no token is inlined
 * into the committed collector config.
 */
#[AsDriver('grafana')]
final class GrafanaOtlpMetricsSink implements MetricsSinkInterface
{
    public function __construct(
        private readonly string $host,
        private readonly OtlpProtocol $protocol = OtlpProtocol::HttpProtobuf,
        private readonly ?int $port = null,
        private readonly bool $tlsEnabled = true,
        private readonly ?string $headersEnvRef = 'OBSERVABILITY_GRAFANA_OTLP_HEADERS',
        // Grafana Cloud's HTTP OTLP gateway serves the ingest under a `/otlp` base path
        // onto which the exporter appends `/v1/{signal}`. gRPC ingest uses no path.
        // Left null the DSN is bare host:port (correct for a self-hosted LGTM stack).
        private readonly ?string $basePath = null,
    ) {}

    public function name(): string
    {
        return 'grafana';
    }

    public function signals(): array
    {
        return [TelemetrySignal::Metrics, TelemetrySignal::Traces, TelemetrySignal::Logs];
    }

    public function endpoint(): SinkEndpoint
    {
        // A TLS OTLP gateway (Grafana Cloud, or a self-hosted LGTM stack behind an HTTPS
        // ingress) serves ingest on 443 — the bare-OTLP conventions 4317/4318 apply only to
        // a plaintext in-cluster collector. Defaulting to the protocol port here produced
        // `https://host:4318/otlp`, which Grafana Cloud refuses; every operator then had to
        // hand-edit the generated config back to 443. Default TLS endpoints to 443 so the
        // generated exporter is correct out of the box; an explicit port still wins.
        $port = $this->port ?? ($this->tlsEnabled ? 443 : $this->protocol->defaultPort());

        return SinkEndpoint::create(
            host: $this->host,
            protocol: $this->protocol,
            port: $port,
            tlsEnabled: $this->tlsEnabled,
            headersEnvRef: $this->headersEnvRef,
            basePath: $this->basePath,
        );
    }

    public function exporterConfig(): ExporterConfig
    {
        $endpoint = $this->endpoint();

        $settings = [
            'endpoint' => $endpoint->dsn(),
            'tls' => ['insecure' => !$this->tlsEnabled],
        ];

        if ($this->headersEnvRef !== null) {
            $settings['headers'] = ['Authorization' => '${env:' . $this->headersEnvRef . '}'];
        }

        // otlp = gRPC; otlphttp = HTTP/protobuf.
        $type = $this->protocol === OtlpProtocol::Grpc ? 'otlp' : 'otlphttp';

        return ExporterConfig::create($type, $settings);
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SinkCapability::Metrics->value => true,
            SinkCapability::Traces->value => true,
            SinkCapability::Logs->value => true,
            SinkCapability::OffHost->value => true,
            SinkCapability::DiskBuffering->value => true,
            SinkCapability::OtlpNative->value => true,
            SinkCapability::Tls->value => $this->tlsEnabled,
        ], [
            'default_protocol' => $this->protocol->value,
        ]);
    }
}
