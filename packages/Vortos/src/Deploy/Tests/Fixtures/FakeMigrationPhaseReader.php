<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Fixtures;

use Vortos\Migration\Schema\MigrationPhase;
use Vortos\Migration\Schema\MigrationPhaseReaderInterface;

final class FakeMigrationPhaseReader implements MigrationPhaseReaderInterface
{
    /** @param array<string, MigrationPhase> $phases keyed by migration id */
    public function __construct(private readonly array $phases = [])
    {
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
}
