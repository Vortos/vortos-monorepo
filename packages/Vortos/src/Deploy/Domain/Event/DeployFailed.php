<?php

declare(strict_types=1);

namespace Vortos\Deploy\Domain\Event;

/**
 * Recorded when a deploy starts (preflight clear) but fails before/without a
 * successful cutover. errorMessage must already be scrubbed by the caller
 * (no secret/PII material) before this event is constructed.
 */
final class DeployFailed
{
    public function __construct(
        public readonly string $env,
        public readonly string $actorId,
        public readonly string $actorIdentitySource,
        public readonly string $buildId,
        public readonly string $gitSha,
        public readonly string $imageDigest,
        public readonly string $schemaFingerprintId,
        public readonly ?string $reason,
        public readonly string $errorClass,
        public readonly string $errorMessage,
    ) {
    }
}
