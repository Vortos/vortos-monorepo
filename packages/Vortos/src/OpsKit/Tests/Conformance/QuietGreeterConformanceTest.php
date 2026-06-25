<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Conformance;

use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\OpsKit\Tests\Fixtures\Demo\QuietGreeter;

final class QuietGreeterConformanceTest extends GreeterConformanceTestCase
{
    protected function createDriver(): DriverInterface
    {
        return new QuietGreeter();
    }

    protected function expectedKey(): string
    {
        return 'quiet';
    }
}
