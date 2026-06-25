<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Service;

use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Attestation\AttestationBundle;
use Vortos\Security\SupplyChain\Model\Provenance\SlsaProvenance;
use Vortos\Security\SupplyChain\Model\Sbom\SbomDocument;
use Vortos\Security\SupplyChain\Model\Signature\Signature;

final class AttestationAssembler
{
    public function assemble(
        ArtifactDigest $digest,
        ?SbomDocument $sbom = null,
        ?Signature $signature = null,
        ?SlsaProvenance $provenance = null,
    ): AttestationBundle {
        return new AttestationBundle(
            artifactDigest: $digest,
            sbom: $sbom,
            signature: $signature,
            provenance: $provenance,
        );
    }
}
