<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model;

class SupplyChainException extends \RuntimeException
{
    public static function unsignedArtifact(string $digest): self
    {
        return new UnsignedArtifactException(sprintf(
            'Artifact "%s" is unsigned — deployment refused.',
            $digest,
        ));
    }

    public static function signatureMismatch(string $digest, string $reason): self
    {
        return new SignatureMismatchException(sprintf(
            'Signature verification failed for "%s": %s',
            $digest,
            $reason,
        ));
    }

    public static function provenanceChain(string $reason): self
    {
        return new ProvenanceChainException(sprintf(
            'Provenance chain verification failed: %s',
            $reason,
        ));
    }

    public static function scanFailed(string $reason): self
    {
        return new ScanFailedException(sprintf(
            'Vulnerability scan failed: %s',
            $reason,
        ));
    }

    public static function kevCatalogUnavailable(string $reason): self
    {
        return new KevCatalogUnavailableException(sprintf(
            'CISA KEV catalog unavailable (fail-closed): %s',
            $reason,
        ));
    }
}
