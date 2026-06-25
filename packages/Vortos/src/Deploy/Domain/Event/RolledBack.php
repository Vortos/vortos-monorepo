<?php

declare(strict_types=1);

namespace Vortos\Deploy\Domain\Event;

/**
 * Recorded when a rollback (manual or deploy-triggered auto-rollback) completes.
 */
final class RolledBack
{
    public function __construct(
        public readonly string $env,
        public readonly string $actorId,
        public readonly string $actorIdentitySource,
        public readonly string $fromBuildId,
        public readonly string $toBuildId,
        public readonly ?string $reason,
    ) {
    }
}
