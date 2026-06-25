<?php

declare(strict_types=1);

namespace Vortos\Observability\Tests\Conformance;

use Vortos\Observability\Marker\Driver\Null\NullMarkerEmitter;
use Vortos\Observability\Marker\MarkerEmitterInterface;
use Vortos\Observability\Testing\MarkerEmitterConformanceTestCase;

final class NullMarkerEmitterConformanceTest extends MarkerEmitterConformanceTestCase
{
    protected function createEmitter(): MarkerEmitterInterface
    {
        return new NullMarkerEmitter();
    }

    protected function expectedKey(): string
    {
        return 'null';
    }
}
