<?php

declare(strict_types=1);

namespace Vortos\FeatureFlags\Compliance\Export;

/**
 * Immutable filter for the audit export query.
 * All fields are optional; null means "no constraint on this dimension".
 */
final class AuditExportFilter
{
    public function __construct(
        public readonly ?string $flagName      = null,
        public readonly ?string $environment   = null,
        public readonly ?string $projectId     = null,
        public readonly ?string $actorId       = null,
        public readonly ?\DateTimeImmutable $from = null,
        public readonly ?\DateTimeImmutable $to   = null,
        public readonly int $batchSize         = 500,
    ) {
        if ($this->batchSize < 1 || $this->batchSize > 5_000) {
            throw new \InvalidArgumentException('batchSize must be between 1 and 5000');
        }
    }

    /** Whether an audit entry matches this filter. Used by in-memory implementations. */
    public function matches(\Vortos\FeatureFlags\ReadModel\FlagAuditEntry $entry): bool
    {
        if ($this->flagName !== null && $entry->flagName !== $this->flagName) {
            return false;
        }
        if ($this->environment !== null && $entry->environment !== $this->environment) {
            return false;
        }
        if ($this->actorId !== null && $entry->actorId !== $this->actorId) {
            return false;
        }
        if ($this->from !== null) {
            try {
                $at = new \DateTimeImmutable($entry->occurredAt);
                if ($at < $this->from) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }
        if ($this->to !== null) {
            try {
                $at = new \DateTimeImmutable($entry->occurredAt);
                if ($at > $this->to) {
                    return false;
                }
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }
}
