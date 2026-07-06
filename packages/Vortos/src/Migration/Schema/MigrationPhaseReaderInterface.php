<?php

declare(strict_types=1);

namespace Vortos\Migration\Schema;

interface MigrationPhaseReaderInterface
{
    public function phaseOf(string $migrationId): MigrationPhase;

    /**
     * @param list<string> $ids
     * @return array<string, MigrationPhase> keyed by migration ID
     */
    public function phasesFor(array $ids): array;

    /**
     * True when the migration contains destructive DDL but carries no #[DeployPhase] declaration
     * (and no #[AllowFullTableRewrite] opt-out) — used by the deploy-runtime guard and
     * deploy:doctor to emit a precise remediation instead of a generic contract error.
     */
    public function isDestructiveAndUnannotated(string $migrationId): bool;
}
