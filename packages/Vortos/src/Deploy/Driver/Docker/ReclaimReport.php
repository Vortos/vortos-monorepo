<?php

declare(strict_types=1);

namespace Vortos\Deploy\Driver\Docker;

/**
 * Human- and machine-readable outcome of an {@see ImageReclaimer::reclaim()} pass.
 *
 * Reclaim is best-effort by construction — every docker sub-command is isolated and a
 * failure is recorded as a note rather than thrown — so this report is the single place
 * that carries what actually happened, for the deploy audit trail and the scheduled GC log.
 */
final readonly class ReclaimReport
{
    /** @param list<string> $notes */
    public function __construct(
        public bool $enabled,
        public int $removed,
        public int $kept,
        public array $notes,
    ) {}

    public static function disabled(): self
    {
        return new self(enabled: false, removed: 0, kept: 0, notes: ['image reclaim disabled']);
    }

    public function summary(): string
    {
        if (!$this->enabled) {
            return 'image reclaim disabled';
        }

        return 'image reclaim: ' . implode('; ', $this->notes);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'enabled' => $this->enabled,
            'removed' => $this->removed,
            'kept' => $this->kept,
            'notes' => $this->notes,
        ];
    }
}
