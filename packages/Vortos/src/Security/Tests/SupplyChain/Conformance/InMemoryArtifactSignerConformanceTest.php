<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Conformance;

use Vortos\Security\SupplyChain\Driver\InMemory\InMemoryArtifactSigner;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;
use Vortos\Security\SupplyChain\Port\ArtifactSignerInterface;

final class InMemoryArtifactSignerConformanceTest extends ArtifactSignerConformanceTestCase
{
    private InMemoryArtifactSigner $signer;

    protected function setUp(): void
    {
        $this->signer = new InMemoryArtifactSigner();
    }

    protected function createDriver(): ArtifactSignerInterface
    {
        return $this->signer;
    }

    protected function expectedKey(): string
    {
        return 'in-memory';
    }

    protected function verificationPolicy(): ?VerificationPolicy
    {
        return VerificationPolicy::publicKey($this->signer->publicKeyFingerprint());
    }
}
