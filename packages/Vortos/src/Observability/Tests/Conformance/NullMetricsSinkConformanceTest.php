<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Conformance;

use Vortos\Observability\Driver\Null\NullMetricsSink;
use Vortos\Observability\Sink\MetricsSinkInterface;
use Vortos\Observability\Testing\MetricsSinkConformanceTestCase;

final class NullMetricsSinkConformanceTest extends MetricsSinkConformanceTestCase
{
    protected function createSink(): MetricsSinkInterface
    {
        return new NullMetricsSink();
    }

    protected function expectedKey(): string
    {
        return 'null';
    }
}
