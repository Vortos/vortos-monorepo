<?php

declare(strict_types=1);

namespace Vortos\Analytics\Tests\Driver;

use Vortos\Analytics\AnalyticsInterface;
use Vortos\Analytics\Driver\Null\NullAnalytics;
use Vortos\Analytics\Testing\AnalyticsConformanceTestCase;

final class NullAnalyticsConformanceTest extends AnalyticsConformanceTestCase
{
    protected function createAnalytics(): AnalyticsInterface
    {
        return new NullAnalytics();
    }

    protected function expectedKey(): string
    {
        return 'null';
    }
}
