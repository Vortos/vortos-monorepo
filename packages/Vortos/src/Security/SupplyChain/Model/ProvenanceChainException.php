<?php

declare(strict_types=1);

namespace Vortos\Security\SupplyChain\Model;

final class ProvenanceChainException extends SupplyChainException
{
    public static function subjectMismatch(string $expected, string $actual): self
    {
        return new self(sprintf(
            'Provenance subject digest mismatch: expected %s, got %s.',
            $expected,
            $actual,
        ));
    }

    public static function builderMismatch(string $expected, string $actual): self
    {
        return new self(sprintf(
            'Provenance builder ID mismatch: expected "%s", got "%s".',
            $expected,
            $actual,
        ));
    }

    public static function subjectNotFound(string $digest): self
    {
        return new self(sprintf(
            'Expected image digest %s not found among provenance subjects.',
            $digest,
        ));
    }

    public static function malformedMaterial(string $uri): self
    {
        return new self(sprintf(
            'Provenance material "%s" has a malformed digest.',
            $uri,
        ));
    }

    public static function baseImageMismatch(string $expected): self
    {
        return new self(sprintf(
            'Base image digest %s not found among provenance materials.',
            $expected,
        ));
    }

    public static function contentHashUnstable(): self
    {
        return new self('Attestation bundle content hash is not stable (tamper-evident violation).');
    }
}
