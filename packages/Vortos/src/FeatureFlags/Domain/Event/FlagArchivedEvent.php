<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Domain\Event;

/**
 * A feature flag was archived (soft-removed). Pure domain event payload (F1/F2/F3).
 *
 * `finalState` is the flag's last snapshot before archival, so the audit log and a
 * possible un-archive/revert keep a complete record.
 */
final class FlagArchivedEvent
{
    /**
     * @param array<string,mixed> $finalState
     */
    public function __construct(
        public readonly string $flagId,
        public readonly string $name,
        public readonly array $finalState,
        public readonly string $actorId,
        public readonly ?string $reason = null,
        public readonly string $environment = 'production',
    ) {}
}
