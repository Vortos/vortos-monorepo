<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

final class StaleManifestException extends DeployException
{
    public static function rollback(int $manifestVersion, int $lastAppliedVersion): self
    {
        return new self(sprintf(
            'Manifest version %d is not newer than last applied version %d — rollback rejected.',
            $manifestVersion,
            $lastAppliedVersion,
        ));
    }

    public static function staleIssuedAt(\DateTimeImmutable $issuedAt, int $freshnessWindowSeconds): self
    {
        return new self(sprintf(
            'Manifest issued at %s is outside the freshness window of %d seconds.',
            $issuedAt->format(\DateTimeInterface::ATOM),
            $freshnessWindowSeconds,
        ));
    }
}
