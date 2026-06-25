<?php

declare(strict_types=1);

namespace Vortos\Deploy\Domain\Event;

/**
 * Recorded when deploy:doctor's preflight refuses a deploy — the forensically
 * important "who tried to deploy through a red gate, when, and which check blocked
 * it" case (§4.4). Carries the failed preflight check ids, never secret material.
 */
final class DeployRefused
{
    /**
     * @param list<string> $failedCheckIds
     */
    public function __construct(
        public readonly string $env,
        public readonly string $actorId,
        public readonly string $actorIdentitySource,
        public readonly string $buildId,
        public readonly string $gitSha,
        public readonly string $imageDigest,
        public readonly string $schemaFingerprintId,
        public readonly ?string $reason,
        public readonly array $failedCheckIds,
    ) {
    }
}
