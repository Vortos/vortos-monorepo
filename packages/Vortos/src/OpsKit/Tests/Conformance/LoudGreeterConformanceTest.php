<?php

declare(strict_types=1);

namespace Vortos\OpsKit\Tests\Conformance;

use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\OpsKit\Tests\Fixtures\Demo\LoudGreeter;

final class LoudGreeterConformanceTest extends GreeterConformanceTestCase
{
    protected function createDriver(): DriverInterface
    {
        return new LoudGreeter();
    }

    protected function expectedKey(): string
    {
        return 'loud';
    }
}
