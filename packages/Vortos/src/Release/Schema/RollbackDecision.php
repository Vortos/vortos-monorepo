<?php

declare(strict_types=1);

namespace Vortos\Release\Schema;

final readonly class RollbackDecision
{
    /**
     * @param list<string> $offendingMigrations Migration IDs causing the refusal
     */
    private function __construct(
        public bool $legal,
        public RollbackRefusalReason $reason,
        public array $offendingMigrations,
    ) {}

    public static function allowed(): self
    {
        return new self(true, RollbackRefusalReason::Legal, []);
    }

    /** @param list<string> $missingMigrations */
    public static function targetNotSubset(array $missingMigrations): self
    {
        return new self(false, RollbackRefusalReason::TargetNotSubset, $missingMigrations);
    }

    /** @param list<string> $unknownIds */
    public static function unknownAppliedMigration(array $unknownIds): self
    {
        return new self(false, RollbackRefusalReason::UnknownAppliedMigration, $unknownIds);
    }

    public function explain(): string
    {
        if ($this->legal) {
            return 'Rollback is safe: target schema fingerprint is a subset of the currently applied set.';
        }

        $ids = implode(', ', $this->offendingMigrations);

        return match ($this->reason) {
            RollbackRefusalReason::TargetNotSubset => sprintf(
                'Rollback refused: target requires migration(s) [%s] that are not in the currently applied set. '
                . 'Recovery: roll forward to apply the missing migration(s), or create a compensating expand migration.',
                $ids,
            ),
            RollbackRefusalReason::UnknownAppliedMigration => sprintf(
                'Rollback refused: the currently applied set contains unknown migration(s) [%s] not present in any recorded manifest. '
                . 'This may indicate a manual hotfix. Recovery: record a manifest for the current state before rolling back.',
                $ids,
            ),
            RollbackRefusalReason::Legal => 'Rollback is safe.',
        };
    }
}
