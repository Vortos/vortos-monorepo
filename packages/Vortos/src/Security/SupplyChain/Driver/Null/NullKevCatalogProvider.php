<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\Null;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException;
use Vortos\Security\SupplyChain\Capability\SupplyChainCapabilityKey;
use Vortos\Security\SupplyChain\Model\Vulnerability\KevCatalog;
use Vortos\Security\SupplyChain\Port\KevCatalogProviderInterface;

#[AsDriver('null')]
final class NullKevCatalogProvider implements KevCatalogProviderInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SupplyChainCapabilityKey::KevAware->value => false,
        ]);
    }

    public function catalog(): KevCatalog
    {
        throw UnsupportedCapabilityException::for('null', SupplyChainCapabilityKey::KevAware);
    }
}
