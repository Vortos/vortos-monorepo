<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Port;

use Vortos\OpsKit\Driver\DriverInterface;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Signature\Signature;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;
use Vortos\Security\SupplyChain\Model\Signature\VerificationResult;
use Vortos\Security\SupplyChain\Model\SupplyChainException;

interface ArtifactSignerInterface extends DriverInterface
{
    /** @throws SupplyChainException on signing failure (fail-closed; never silent) */
    public function sign(ArtifactDigest $digest): Signature;

    public function verify(ArtifactDigest $digest, VerificationPolicy $policy): VerificationResult;
}
