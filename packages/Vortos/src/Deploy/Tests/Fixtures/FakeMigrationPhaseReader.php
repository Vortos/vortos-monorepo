<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;

final class FakeMigrationPhaseReader implements MigrationPhaseReaderInterface
{
    /**
     * @param array<string, MigrationPhase> $phases keyed by migration id
     * @param list<string> $destructiveUnannotated migration ids that are destructive & un-annotated
     */
    public function __construct(
        private readonly array $phases = [],
        private readonly array $destructiveUnannotated = [],
    ) {
    }

    public function phaseOf(string $migrationId): MigrationPhase
    {
        return $this->phases[$migrationId] ?? MigrationPhase::Expand;
    }

    public function phasesFor(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = $this->phaseOf($id);
        }

        return $out;
    }

    public function isDestructiveAndUnannotated(string $migrationId): bool
    {
        return in_array($migrationId, $this->destructiveUnannotated, true);
    }
}
