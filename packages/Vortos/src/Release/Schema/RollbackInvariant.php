<?php

declare(strict_types=1);

namespace Vortos\Release\Schema;

final class RollbackInvariant
{
    public static function evaluate(
        SchemaFingerprint $target,
        SchemaFingerprint $applied,
        KnownMigrationSet $known,
    ): RollbackDecision {
        $unknowns = $known->unknownsIn($applied);

        if ($unknowns !== []) {
            return RollbackDecision::unknownAppliedMigration($unknowns);
        }

        $missing = $target->missingFrom($applied);

        if ($missing !== []) {
            return RollbackDecision::targetNotSubset($missing);
        }

        return RollbackDecision::allowed();
    }
}
