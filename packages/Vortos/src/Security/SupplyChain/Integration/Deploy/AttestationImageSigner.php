<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Integration\Deploy;

use Vortos\Deploy\Oci\ImageSignerInterface;
use Vortos\Deploy\Registry\ImageReference;
use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;
use Vortos\Security\SupplyChain\Port\ArtifactSignerRegistry;

final class AttestationImageSigner implements ImageSignerInterface
{
    public function __construct(
        private readonly ArtifactSignerRegistry $signerRegistry,
        private readonly string $signerKey,
        private readonly ?VerificationPolicy $policy = null,
    ) {}

    public function sign(ImageReference $image): void
    {
        if ($image->digest === null) {
            return;
        }

        $signer = $this->signerRegistry->signer($this->signerKey);
        $signer->sign(new ArtifactDigest($image->digest));
    }

    public function verify(ImageReference $image): bool
    {
        if ($image->digest === null) {
            return false;
        }

        if ($this->policy === null) {
            return false;
        }

        $signer = $this->signerRegistry->signer($this->signerKey);
        $result = $signer->verify(new ArtifactDigest($image->digest), $this->policy);

        return $result->ok;
    }
}
