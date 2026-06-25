<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Conformance;

use Vortos\Health\Testing\UptimeMonitorConformanceTestCase;
use Vortos\Health\Uptime\Driver\Null\NullUptimeMonitor;
use Vortos\Health\Uptime\UptimeMonitorInterface;

final class NullUptimeMonitorConformanceTest extends UptimeMonitorConformanceTestCase
{
    protected function createMonitor(): UptimeMonitorInterface
    {
        return new NullUptimeMonitor();
    }

    protected function expectedKey(): string
    {
        return 'null';
    }
}
