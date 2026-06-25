<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Architecture;

use PHPUnit\Framework\TestCase;
use Vortos\Observability\Driver\GrafanaOtlp\GrafanaOtlpMetricsSink;
use Vortos\Observability\Driver\Glitchtip\CurlErrorTransport;
use Vortos\Observability\Driver\Glitchtip\GlitchtipErrorSink;
use Vortos\Observability\Buffer\BoundedSpool;
use Vortos\Observability\Sink\Capability\SinkCapability;

/**
 * §12.4 invariant: the real off-host backends must declare `off_host=true` so the
 * config-time gate can refuse an on-host (co-located) observability plane for prod.
 */
final class OffHostInvariantTest extends TestCase
{
    public function test_grafana_metrics_sink_is_off_host(): void
    {
        self::assertTrue(
            (new GrafanaOtlpMetricsSink('collector.example.com'))->capabilities()->supports(SinkCapability::OffHost),
        );
    }

    public function test_glitchtip_error_sink_is_off_host(): void
    {
        $sink = new GlitchtipErrorSink(
            new BoundedSpool(sys_get_temp_dir() . '/vortos-offhost-' . bin2hex(random_bytes(4)) . '/e.spool', 1024),
            new CurlErrorTransport(),
        );

        self::assertTrue($sink->capabilities()->supports(SinkCapability::OffHost));
    }
}
