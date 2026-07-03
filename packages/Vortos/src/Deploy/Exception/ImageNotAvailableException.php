<?php

declare(strict_types=1);

namespace Vortos\Deploy\Exception;

/**
 * Thrown by a target's assertImageAvailable() pre-release check when the pinned image
 * is not present in its registry, or when the registry's live digest for the reference
 * does not match the digest the deploy was told to pin. Fail-closed: the deploy refuses
 * before any mutation rather than surfacing a broken pull on the target.
 */
final class ImageNotAvailableException extends DeployException
{
    public static function notFound(string $reference, string $reason): self
    {
        return new self(sprintf(
            'Image "%s" is not available in its registry: %s',
            $reference,
            $reason,
        ));
    }

    public static function digestMismatch(string $reference, string $expected, string $actual): self
    {
        return new self(sprintf(
            'Image "%s" resolved to digest "%s" but "%s" was pinned; the registry content changed under the pin.',
            $reference,
            $actual,
            $expected,
        ));
    }
}
