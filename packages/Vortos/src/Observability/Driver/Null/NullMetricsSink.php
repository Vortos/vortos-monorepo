<?php

declare(strict_types=1);

namespace Vortos\Observability\Driver\Null;

use Vortos\Observability\Sink\Capability\SinkCapability;
use Vortos\Observability\Sink\ExporterConfig;
use Vortos\Observability\Sink\MetricsSinkInterface;
use Vortos\Observability\Sink\OtlpProtocol;
use Vortos\Observability\Sink\SinkEndpoint;
use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;

/**
 * Explicit no-op metrics sink — makes "observability disabled" a validated, chosen
 * state rather than an accident. Declares `off_host=false`, so selecting it for a
 * prod target is refusable at config-validation time.
 */
#[AsDriver('null')]
final class NullMetricsSink implements MetricsSinkInterface
{
    public function name(): string
    {
        return 'null';
    }

    public function signals(): array
    {
        return [];
    }

    public function endpoint(): SinkEndpoint
    {
        return SinkEndpoint::create('127.0.0.1', OtlpProtocol::HttpProtobuf, tlsEnabled: false);
    }

    public function exporterConfig(): ExporterConfig
    {
        return ExporterConfig::create('nop', []);
    }

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SinkCapability::Metrics->value => false,
            SinkCapability::Traces->value => false,
            SinkCapability::Logs->value => false,
            SinkCapability::OffHost->value => false,
            SinkCapability::DiskBuffering->value => false,
            SinkCapability::OtlpNative->value => false,
            SinkCapability::Tls->value => false,
        ]);
    }
}
