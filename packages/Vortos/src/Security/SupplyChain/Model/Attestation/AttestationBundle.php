<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model\Attestation;

use Vortos\Security\SupplyChain\Model\ArtifactDigest;
use Vortos\Security\SupplyChain\Model\Provenance\SlsaProvenance;
use Vortos\Security\SupplyChain\Model\Sbom\SbomDocument;
use Vortos\Security\SupplyChain\Model\Signature\Signature;

final readonly class AttestationBundle
{
    public function __construct(
        public ArtifactDigest $artifactDigest,
        public ?SbomDocument $sbom = null,
        public ?Signature $signature = null,
        public ?SlsaProvenance $provenance = null,
    ) {}

    public function contentHash(): string
    {
        $parts = [
            'artifact_digest' => $this->artifactDigest->toString(),
        ];

        if ($this->sbom !== null) {
            $parts['sbom_hash'] = $this->sbom->contentHash();
        }

        if ($this->signature !== null) {
            $parts['signature_scheme'] = $this->signature->scheme->value;
        }

        if ($this->provenance !== null) {
            $normalized = $this->provenance->toArray();
            ksort($normalized);
            $parts['provenance'] = json_encode($normalized, \JSON_THROW_ON_ERROR);
        }

        ksort($parts);

        return 'sha256:' . hash('sha256', json_encode($parts, \JSON_THROW_ON_ERROR));
    }

    public function hasSignature(): bool
    {
        return $this->signature !== null;
    }

    public function hasProvenance(): bool
    {
        return $this->provenance !== null;
    }

    public function hasSbom(): bool
    {
        return $this->sbom !== null;
    }

    /** @return array{artifact_digest: string, content_hash: string, has_sbom: bool, has_signature: bool, has_provenance: bool} */
    public function toArray(): array
    {
        return [
            'artifact_digest' => $this->artifactDigest->toString(),
            'content_hash' => $this->contentHash(),
            'has_sbom' => $this->hasSbom(),
            'has_signature' => $this->hasSignature(),
            'has_provenance' => $this->hasProvenance(),
        ];
    }
}
