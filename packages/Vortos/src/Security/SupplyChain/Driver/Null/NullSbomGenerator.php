<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\Null;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException;
use Vortos\Security\SupplyChain\Capability\SupplyChainCapabilityKey;
use Vortos\Security\SupplyChain\Model\ArtifactRef;
use Vortos\Security\SupplyChain\Model\Sbom\SbomDocument;
use Vortos\Security\SupplyChain\Model\Sbom\SbomFormat;
use Vortos\Security\SupplyChain\Port\SbomGeneratorInterface;

#[AsDriver('null')]
final class NullSbomGenerator implements SbomGeneratorInterface
{
    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SupplyChainCapabilityKey::Sbom->value => false,
        ]);
    }

    public function generate(ArtifactRef $ref, SbomFormat $format): SbomDocument
    {
        throw UnsupportedCapabilityException::for('null', SupplyChainCapabilityKey::Sbom);
    }
}
