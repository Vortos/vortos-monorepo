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
}
