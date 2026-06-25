<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Service;

use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Attestation\AttestationBundle;
use Vortos\Security\SupplyChain\Model\ProvenanceChainException;
use Vortos\Security\SupplyChain\Model\Signature\VerificationPolicy;
use Vortos\Security\SupplyChain\Port\ArtifactSignerInterface;

final class AttestationChainVerifier
{
    public function verify(
        AttestationBundle $bundle,
        ArtifactDigest $expectedImageDigest,
        string $expectedBuilderId,
        ?string $expectedBaseImageDigest,
        VerificationPolicy $policy,
        ArtifactSignerInterface $signer,
    ): void {
        if (!$bundle->artifactDigest->equals($expectedImageDigest)) {
            throw ProvenanceChainException::subjectMismatch(
                $expectedImageDigest->toString(),
                $bundle->artifactDigest->toString(),
            );
        }

        if ($bundle->hasSignature()) {
            $result = $signer->verify($bundle->artifactDigest, $policy);
            $result->assertVerified();
        }

        if ($bundle->hasProvenance()) {
            $prov = $bundle->provenance;
            \assert($prov !== null);

            if ($prov->builder->builderId !== $expectedBuilderId) {
                throw ProvenanceChainException::builderMismatch($expectedBuilderId, $prov->builder->builderId);
            }

            $subjectMatch = false;
            foreach ($prov->subjects as $subject) {
                if ($subject->digest->equals($expectedImageDigest)) {
                    $subjectMatch = true;
                    break;
                }
            }

            if (!$subjectMatch) {
                throw ProvenanceChainException::subjectNotFound($expectedImageDigest->toString());
            }

            foreach ($prov->materials as $material) {
                if ($material->digest->hex() === '' || strlen($material->digest->hex()) !== 64) {
                    throw ProvenanceChainException::malformedMaterial($material->uri);
                }
            }

            if ($expectedBaseImageDigest !== null) {
                $baseMatch = false;
                foreach ($prov->materials as $material) {
                    if ($material->digest->toString() === $expectedBaseImageDigest) {
                        $baseMatch = true;
                        break;
                    }
                }

                if (!$baseMatch) {
                    throw ProvenanceChainException::baseImageMismatch($expectedBaseImageDigest);
                }
            }
        }

        $hash1 = $bundle->contentHash();
        $hash2 = $bundle->contentHash();
        if ($hash1 !== $hash2) {
            throw ProvenanceChainException::contentHashUnstable();
        }
    }
}
