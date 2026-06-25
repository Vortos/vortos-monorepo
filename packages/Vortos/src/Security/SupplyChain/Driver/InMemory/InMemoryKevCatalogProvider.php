<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\InMemory;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Security\SupplyChain\Capability\SupplyChainCapabilityKey;
use Vortos\Security\SupplyChain\Model\Vulnerability\KevCatalog;
use Vortos\Security\SupplyChain\Port\KevCatalogProviderInterface;

#[AsDriver('in-memory')]
final class InMemoryKevCatalogProvider implements KevCatalogProviderInterface
{
    private ?KevCatalog $fixedCatalog = null;

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SupplyChainCapabilityKey::KevAware->value => true,
        ]);
    }

    public function seedCatalog(KevCatalog $catalog): void
    {
        $this->fixedCatalog = $catalog;
    }

    public function catalog(): KevCatalog
    {
        if ($this->fixedCatalog !== null) {
            return $this->fixedCatalog;
        }

        return KevCatalog::fromList([], 'sha256:empty', new \DateTimeImmutable());
    }
}
