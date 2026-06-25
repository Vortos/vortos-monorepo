<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Conformance;

use Vortos\OpsKit\Testing\ConformanceTestCase;
use Vortos\Security\SupplyChain\Capability\SupplyChainCapabilityKey;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\ArtifactRef;
use Vortos\Security\SupplyChain\Model\Sbom\SbomFormat;
use Vortos\Security\SupplyChain\Port\SbomGeneratorInterface;

abstract class SbomGeneratorConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createDriver(): SbomGeneratorInterface;

    final public function test_generate_returns_sbom_document_when_capable(): void
    {
        $driver = $this->createDriver();
        if (!$driver->capabilities()->supports(SupplyChainCapabilityKey::Sbom)) {
            $this->assertRejectsUnsupportedCapability(fn () => $driver->generate(
                new ArtifactRef('test/image', new ArtifactDigest('sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2')),
                SbomFormat::CycloneDxJson,
            ));
            return;
        }

        $doc = $driver->generate(
            new ArtifactRef('test/image', new ArtifactDigest('sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2')),
            SbomFormat::CycloneDxJson,
        );

        self::assertNotSame('', $doc->specVersion);
        self::assertNotSame('', $doc->contentHash());
    }
}
