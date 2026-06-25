<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Conformance;

use Vortos\Observability\Marker\Driver\GrafanaOtlp\GrafanaOtlpMarkerEmitter;
use Vortos\Observability\Marker\MarkerEmitterInterface;
use Vortos\Observability\Marker\MarkerTransportInterface;
use Vortos\Observability\Testing\MarkerEmitterConformanceTestCase;

final class GrafanaOtlpMarkerEmitterConformanceTest extends MarkerEmitterConformanceTestCase
{
    protected function createEmitter(): MarkerEmitterInterface
    {
        // A transport that always explodes — proves emit() never propagates.
        $explodingTransport = new class implements MarkerTransportInterface {
            public function post(string $url, array $body, array $headers): void
            {
                throw new \RuntimeException('boom');
            }
        };

        return new GrafanaOtlpMarkerEmitter('https://otlp-gateway.example.invalid/v1/logs', $explodingTransport);
    }

    protected function expectedKey(): string
    {
        return 'grafana';
    }
}
