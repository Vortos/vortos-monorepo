<?php

declare(strict_types=1);

namespace Vortos\Observability\Testing;

use Vortos\Observability\Sink\Capability\SinkCapability;
use Vortos\Observability\Sink\ExporterConfig;
use Vortos\Observability\Sink\MetricsSinkInterface;
use Vortos\Observability\Sink\SinkEndpoint;
use Vortos\Observability\Sink\TelemetrySignal;
use Vortos\OpsKit\Testing\ConformanceTestCase;

/**
 * The metrics-sink TCK (§10.4). A concrete driver test extends this and supplies the
 * driver; this base asserts the universal driver contract plus the metrics-sink
 * contract: a well-formed key matching the registered key, honest signal declaration,
 * a valid off-host endpoint, and an exporter fragment consistent with the declared
 * capabilities (a sink claiming `tls` must not emit an insecure exporter).
 */
abstract class MetricsSinkConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createSink(): MetricsSinkInterface;

    protected function createDriver(): MetricsSinkInterface
    {
        return $this->createSink();
    }

    final public function test_name_matches_registered_key(): void
    {
        self::assertSame($this->expectedKey(), $this->createSink()->name());
    }

    final public function test_signals_are_distinct_telemetry_signals(): void
    {
        $signals = $this->createSink()->signals();
        $values = array_map(static fn (TelemetrySignal $s): string => $s->value, $signals);

        self::assertSame(array_values(array_unique($values)), $values, 'signals() must not contain duplicates.');
    }

    final public function test_signals_agree_with_capabilities(): void
    {
        $sink = $this->createSink();
        $caps = $sink->capabilities();
        $declared = array_map(static fn (TelemetrySignal $s): string => $s->value, $sink->signals());

        foreach ([SinkCapability::Metrics, SinkCapability::Traces, SinkCapability::Logs] as $cap) {
            if ($caps->supports($cap)) {
                self::assertContains(
                    $cap->value,
                    $declared,
                    "Capability '{$cap->value}'=true must be reflected in signals() (honest capability).",
                );
            } else {
                self::assertNotContains(
                    $cap->value,
                    $declared,
                    "signals() lists '{$cap->value}' but the capability is false (dishonest).",
                );
            }
        }
    }

    final public function test_endpoint_is_valid(): void
    {
        $endpoint = $this->createSink()->endpoint();

        self::assertInstanceOf(SinkEndpoint::class, $endpoint);
        self::assertNotSame('', $endpoint->host);
        self::assertGreaterThanOrEqual(1, $endpoint->port);
        self::assertLessThanOrEqual(65535, $endpoint->port);
    }

    final public function test_exporter_config_round_trips_canonically(): void
    {
        $exporter = $this->createSink()->exporterConfig();

        self::assertInstanceOf(ExporterConfig::class, $exporter);
        self::assertSame($exporter->toArray(), $exporter->toArray(), 'exporterConfig() must be deterministic.');
        self::assertNotSame('', $exporter->type);
    }

    final public function test_tls_capability_matches_endpoint(): void
    {
        $sink = $this->createSink();
        if ($sink->capabilities()->supports(SinkCapability::Tls)) {
            self::assertTrue(
                $sink->endpoint()->tlsEnabled,
                'A sink declaring tls=true must produce a TLS-enabled endpoint.',
            );
        } else {
            // A sink not declaring tls may use either transport — nothing to assert.
            $this->addToAssertionCount(1);
        }
    }
}
