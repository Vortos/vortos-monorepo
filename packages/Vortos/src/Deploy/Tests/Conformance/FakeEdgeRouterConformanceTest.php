<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Conformance;

use Vortos\Deploy\Cutover\EdgeRouterInterface;
use Vortos\Deploy\Testing\EdgeRouterConformanceTestCase;
use Vortos\Deploy\Tests\Fixtures\FakeEdgeRouter;

final class FakeEdgeRouterConformanceTest extends EdgeRouterConformanceTestCase
{
    protected function createRouter(): EdgeRouterInterface
    {
        return new FakeEdgeRouter();
    }

    protected function expectedKey(): string
    {
        return 'fake';
    }
}
