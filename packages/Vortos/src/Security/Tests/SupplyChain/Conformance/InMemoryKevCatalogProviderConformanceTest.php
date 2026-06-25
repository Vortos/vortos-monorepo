<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Conformance;

use Vortos\Security\SupplyChain\Driver\InMemory\InMemoryKevCatalogProvider;
use Vortos\Security\SupplyChain\Port\KevCatalogProviderInterface;

final class InMemoryKevCatalogProviderConformanceTest extends KevCatalogProviderConformanceTestCase
{
    protected function createDriver(): KevCatalogProviderInterface
    {
        return new InMemoryKevCatalogProvider();
    }

    protected function expectedKey(): string
    {
        return 'in-memory';
    }
}
