<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Conformance;

use Vortos\Security\SupplyChain\Driver\Null\NullSbomGenerator;
use Vortos\Security\SupplyChain\Port\SbomGeneratorInterface;

final class NullSbomGeneratorConformanceTest extends SbomGeneratorConformanceTestCase
{
    protected function createDriver(): SbomGeneratorInterface
    {
        return new NullSbomGenerator();
    }

    protected function expectedKey(): string
    {
        return 'null';
    }
}
