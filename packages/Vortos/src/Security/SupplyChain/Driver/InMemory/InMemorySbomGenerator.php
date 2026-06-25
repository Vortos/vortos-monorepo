<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\InMemory;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\Security\SupplyChain\Capability\SupplyChainCapabilityKey;
use Vortos\Security\SupplyChain\Model\ArtifactRef;
use Vortos\Security\SupplyChain\Model\Sbom\SbomComponent;
use Vortos\Security\SupplyChain\Model\Sbom\SbomDocument;
use Vortos\Security\SupplyChain\Model\Sbom\SbomFormat;
use Vortos\Security\SupplyChain\Port\SbomGeneratorInterface;

#[AsDriver('in-memory')]
final class InMemorySbomGenerator implements SbomGeneratorInterface
{
    private ?SbomDocument $fixedDocument = null;

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SupplyChainCapabilityKey::Sbom->value => true,
        ]);
    }

    public function seedDocument(SbomDocument $document): void
    {
        $this->fixedDocument = $document;
    }

    public function generate(ArtifactRef $ref, SbomFormat $format): SbomDocument
    {
        if ($this->fixedDocument !== null) {
            return $this->fixedDocument;
        }

        return new SbomDocument(
            format: $format,
            specVersion: '1.5',
            components: [
                new SbomComponent(
                    name: 'test-component',
                    version: '1.0.0',
                    purl: 'pkg:oci/' . $ref->repository . '@' . $ref->digest->hex(),
                ),
            ],
        );
    }
}
