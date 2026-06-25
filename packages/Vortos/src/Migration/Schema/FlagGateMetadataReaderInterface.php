<?php

declare(strict_types=1);

namespace Vortos\Migration\Schema;

interface FlagGateMetadataReaderInterface
{
    /**
     * Returns the declared flag-gate spec for a migration, or null when the migration
     * carries no #[GatedByFlag] declaration. Absence means "no exposure-telemetry mapping
     * exists" — callers must treat that as fail-closed, never as an implicit clear.
     */
    public function flagGateFor(string $migrationId): ?FlagGateSpec;
}
