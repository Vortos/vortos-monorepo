<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Conformance;

use Vortos\Observability\Driver\GrafanaOtlp\GrafanaOtlpMetricsSink;
use Vortos\Observability\Sink\MetricsSinkInterface;
use Vortos\Observability\Testing\MetricsSinkConformanceTestCase;

final class GrafanaOtlpMetricsSinkConformanceTest extends MetricsSinkConformanceTestCase
{
    protected function createSink(): MetricsSinkInterface
    {
        return new GrafanaOtlpMetricsSink('collector.example.com');
    }

    protected function expectedKey(): string
    {
        return 'grafana';
    }
}
