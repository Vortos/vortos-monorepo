<?php

declare(strict_types=1);

namespace Vortos\Health\Tests\Conformance;

use Vortos\Health\Testing\UptimeMonitorConformanceTestCase;
use Vortos\Health\Tests\Fixtures\FakeUptimeMonitor;
use Vortos\Health\Uptime\UptimeMonitorInterface;

final class FakeUptimeMonitorConformanceTest extends UptimeMonitorConformanceTestCase
{
    protected function createMonitor(): UptimeMonitorInterface
    {
        return new FakeUptimeMonitor();
    }

    protected function expectedKey(): string
    {
        return 'fake';
    }
}
