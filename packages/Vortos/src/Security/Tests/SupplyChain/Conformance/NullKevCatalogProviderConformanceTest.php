<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Conformance;

use Vortos\Security\SupplyChain\Driver\Null\NullKevCatalogProvider;
use Vortos\Security\SupplyChain\Port\KevCatalogProviderInterface;

final class NullKevCatalogProviderConformanceTest extends KevCatalogProviderConformanceTestCase
{
    protected function createDriver(): KevCatalogProviderInterface
    {
        return new NullKevCatalogProvider();
    }

    protected function expectedKey(): string
    {
        return 'null';
    }
}
