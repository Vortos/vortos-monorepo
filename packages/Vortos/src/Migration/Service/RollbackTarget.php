<?php

declare(strict_types=1);

namespace Vortos\Migration\Service;

use Doctrine\Migrations\Metadata\ExecutedMigration;
use Doctrine\Migrations\Metadata\ExecutedMigrationsList;
use Doctrine\Migrations\Version\Comparator;
use Doctrine\Migrations\Version\Version;

/**
 * The version to roll back TO so that exactly the last N executed migrations are undone.
 *
 * ## Why this is its own class
 *
 * `vortos:migrate:rollback --steps=N` crashed on every partial rollback: it took `array_keys()` of
 * `ExecutedMigrationsList::getItems()`, which is a LIST of ExecutedMigration objects, not a map
 * keyed by version as the docblock claimed — so the "versions" were 0..n-1 and `new Version(int)`
 * threw a TypeError. Static analysis believed the docblock and never saw it. Taking the real type
 * here, and the version from each object, is what the analyser can now check; this class is small
 * enough to test against Doctrine's own list and comparator.
 *
 * ## "Last" means last in migration order
 *
 * Doctrine's plan calculator walks migrations in its comparator's order, so "the last N" has to be
 * counted in that same order — not by execution time and not by a separate sort.
 */
final class RollbackTarget
{
    public static function toUndo(ExecutedMigrationsList $executed, int $steps, Comparator $comparator): Version
    {
        $versions = array_map(
            static fn (ExecutedMigration $migration): Version => $migration->getVersion(),
            $executed->getItems(),
        );
        usort($versions, $comparator->compare(...));

        $count = count($versions);

        // Undoing at least as many as have run means undoing all of them: roll back to the start.
        if ($steps >= $count) {
            return new Version('0');
        }

        return $versions[$count - $steps - 1];
    }
}
