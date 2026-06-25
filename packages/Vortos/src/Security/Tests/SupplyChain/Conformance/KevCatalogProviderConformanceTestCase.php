<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Conformance;

use Vortos\OpsKit\Testing\ConformanceTestCase;
use Vortos\Security\SupplyChain\Capability\SupplyChainCapabilityKey;
use Vortos\Security\SupplyChain\Port\KevCatalogProviderInterface;

abstract class KevCatalogProviderConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createDriver(): KevCatalogProviderInterface;

    final public function test_catalog_returns_kev_catalog_when_capable(): void
    {
        $driver = $this->createDriver();

        if (!$driver->capabilities()->supports(SupplyChainCapabilityKey::KevAware)) {
            $this->assertRejectsUnsupportedCapability(fn () => $driver->catalog());
            return;
        }

        $catalog = $driver->catalog();
        self::assertNotSame('', $catalog->sourceHash);
    }
}
