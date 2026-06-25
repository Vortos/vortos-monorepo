<?php

declare(strict_types=1);

namespace Vortos\Deploy\Domain\Event;

/**
 * Recorded when a deploy completes and traffic has cut over to the new build.
 */
final class DeploySucceeded
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
        public readonly string $targetStatusSummary,
    ) {
    }
}
