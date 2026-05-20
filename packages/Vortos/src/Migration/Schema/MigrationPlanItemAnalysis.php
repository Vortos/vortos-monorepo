<?php

declare(strict_types=1);

namespace Vortos\Migration\Schema;

final class MigrationPlanItemAnalysis
{
    /** All CREATE TABLE / INDEX use IF NOT EXISTS — will succeed regardless of DB state. */
    public const Safe = 'safe';

    /** Objects don't exist yet — run normally. */
    public const Clean = 'clean';

    /** All objects already exist and schema matches — safe to auto-adopt (skip SQL). */
    public const Adoptable = 'adoptable';

    /** Tables exist but columns declared in ALTER TABLE ADD COLUMN are missing — manual intervention. */
    public const NeedsColumns = 'needs_columns';

    /** Some tables exist, some don't — inconsistent state, cannot auto-decide. */
    public const Partial = 'partial';

    /** Could not extract SQL from migration class — run and see. */
    public const Unknown = 'unknown';

    /**
     * @param string[]               $existingTables
     * @param string[]               $missingTables
     * @param array<string, string[]> $missingColumns  keyed by table name
     */
    public function __construct(
        private readonly string $status,
        private readonly array $existingTables = [],
        private readonly array $missingTables = [],
        private readonly array $missingColumns = [],
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    /** @return string[] */
    public function existingTables(): array
    {
        return $this->existingTables;
    }

    /** @return string[] */
    public function missingTables(): array
    {
        return $this->missingTables;
    }

    /** @return array<string, string[]> */
    public function missingColumns(): array
    {
        return $this->missingColumns;
    }

    public function shouldAutoAdopt(): bool
    {
        return $this->status === self::Adoptable;
    }

    public function isBlocker(): bool
    {
        return in_array($this->status, [self::NeedsColumns, self::Partial], true);
    }

    public function willRunNormally(): bool
    {
        return in_array($this->status, [self::Safe, self::Clean, self::Unknown], true);
    }
}
