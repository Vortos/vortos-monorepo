<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Conformance;

use Vortos\Observability\Driver\Null\NullErrorSink;
use Vortos\Observability\Sink\ErrorSinkInterface;
use Vortos\Observability\Testing\ErrorSinkConformanceTestCase;

final class NullErrorSinkConformanceTest extends ErrorSinkConformanceTestCase
{
    protected function createSink(): ErrorSinkInterface
    {
        return new NullErrorSink();
    }

    protected function expectedKey(): string
    {
        return 'null';
    }
}
