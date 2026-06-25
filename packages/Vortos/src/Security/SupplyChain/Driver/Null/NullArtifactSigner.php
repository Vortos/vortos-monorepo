<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Driver\Null;

use Vortos\OpsKit\Attribute\AsDriver;
use Vortos\OpsKit\Driver\Capability\CapabilityDescriptor;
use Vortos\OpsKit\Driver\Exception\UnsupportedCapabilityException;
use Vortos\Security\SupplyChain\Capability\SupplyChainCapabilityKey;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Signature\Signature;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;
use Vortos\Security\SupplyChain\Model\Signature\VerificationResult;
use Vortos\Security\SupplyChain\Port\ArtifactSignerInterface;

#[AsDriver('null')]
final class NullArtifactSigner implements ArtifactSignerInterface
{
    public function __construct(
        private readonly bool $devUnsafeSkipVerification = false,
    ) {}

    public function capabilities(): CapabilityDescriptor
    {
        return CapabilityDescriptor::create([
            SupplyChainCapabilityKey::Signing->value => false,
            SupplyChainCapabilityKey::KeylessSigning->value => false,
            SupplyChainCapabilityKey::RekorTransparency->value => false,
        ]);
    }

    public function sign(ArtifactDigest $digest): Signature
    {
        throw UnsupportedCapabilityException::for('null', SupplyChainCapabilityKey::Signing);
    }

    public function verify(ArtifactDigest $digest, VerificationPolicy $policy): VerificationResult
    {
        if ($this->devUnsafeSkipVerification) {
            return VerificationResult::success();
        }

        throw UnsupportedCapabilityException::for('null', SupplyChainCapabilityKey::Signing);
    }
}
