<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Conformance;

use Vortos\Security\SupplyChain\Driver\Null\NullArtifactSigner;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;
use Vortos\Security\SupplyChain\Port\ArtifactSignerInterface;

final class NullArtifactSignerConformanceTest extends ArtifactSignerConformanceTestCase
{
    protected function createDriver(): ArtifactSignerInterface
    {
        return new NullArtifactSigner();
    }

    protected function expectedKey(): string
    {
        return 'null';
    }

    protected function verificationPolicy(): ?VerificationPolicy
    {
        return null;
    }
}
