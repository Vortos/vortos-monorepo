<?php

declare(strict_types=1);

namespace Vortos\Security\Tests\SupplyChain\Conformance;

use Vortos\OpsKit\Testing\ConformanceTestCase;
use Vortos\Security\SupplyChain\Capability\SupplyChainCapabilityKey;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;
use Vortos\Security\SupplyChain\Port\ArtifactSignerInterface;

abstract class ArtifactSignerConformanceTestCase extends ConformanceTestCase
{
    abstract protected function createDriver(): ArtifactSignerInterface;

    abstract protected function verificationPolicy(): ?VerificationPolicy;

    final public function test_sign_and_verify_round_trip_when_capable(): void
    {
        $driver = $this->createDriver();
        $digest = new ArtifactDigest('sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2');

        if (!$driver->capabilities()->supports(SupplyChainCapabilityKey::Signing)) {
            $this->assertRejectsUnsupportedCapability(fn () => $driver->sign($digest));
            return;
        }

        $sig = $driver->sign($digest);
        self::assertNotNull($sig);

        $policy = $this->verificationPolicy();
        if ($policy !== null) {
            $result = $driver->verify($digest, $policy);
            self::assertTrue($result->ok, 'Round-trip verify must succeed: ' . implode('; ', $result->reasons));
        }
    }
}
