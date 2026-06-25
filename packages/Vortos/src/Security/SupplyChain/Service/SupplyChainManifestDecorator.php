<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Service;

use Vortos\Release\Manifest\BuildManifest;
use Vortos\Release\Manifest\Provenance;
use Vortos\Security\SupplyChain\Model\Attestation\AttestationBundle;

final class SupplyChainManifestDecorator
{
    public function decorate(BuildManifest $manifest, AttestationBundle $bundle): BuildManifest
    {
        $existingProv = $manifest->provenance;

        $signature = null;
        if ($bundle->hasSignature()) {
            $sig = $bundle->signature;
            \assert($sig !== null);
            $signature = $sig->scheme->value . ':' . ($sig->rekorLogIndex !== null ? (string) $sig->rekorLogIndex : 'local');
        }

        $attestation = $bundle->contentHash();

        $provenance = new Provenance(
            builderId: $existingProv !== null ? $existingProv->builderId : 'unknown',
            baseImageDigest: $existingProv?->baseImageDigest,
            signature: $signature,
            attestation: $attestation,
        );

        return new BuildManifest(
            buildId: $manifest->buildId,
            gitSha: $manifest->gitSha,
            imageDigest: $manifest->imageDigest,
            targetArch: $manifest->targetArch,
            environment: $manifest->environment,
            schemaFingerprint: $manifest->schemaFingerprint,
            createdAt: $manifest->createdAt,
            provenance: $provenance,
        );
    }
}
