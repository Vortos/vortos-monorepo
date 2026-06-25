<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Conformance;

use Vortos\Security\SupplyChain\Driver\InMemory\InMemorySbomGenerator;
use Vortos\Security\SupplyChain\Port\SbomGeneratorInterface;

final class InMemorySbomGeneratorConformanceTest extends SbomGeneratorConformanceTestCase
{
    protected function createDriver(): SbomGeneratorInterface
    {
        return new InMemorySbomGenerator();
    }

    protected function expectedKey(): string
    {
        return 'in-memory';
    }
}
