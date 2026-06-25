<?php

declare(strict_types=1);

namespace Vortos\Deploy\Domain\Event;

/**
 * Recorded the moment a deploy is attempted, before preflight is known to pass.
 * Carries the build identity (buildId/gitSha/imageDigest/schemaFingerprintId) so the
 * resulting audit entry links to the Block 3 build manifest.
 */
final class DeployAttempted
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
    ) {
    }
}
