<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Conformance;

use Vortos\Observability\Query\Driver\NullMetricsQuery;
use Vortos\Observability\Query\MetricsQueryInterface;
use Vortos\Observability\Testing\MetricsQueryConformanceTestCase;

final class NullMetricsQueryConformanceTest extends MetricsQueryConformanceTestCase
{
    protected function createQuery(): MetricsQueryInterface
    {
        return new NullMetricsQuery();
    }

    protected function expectedKey(): string
    {
        return 'null';
    }
}
