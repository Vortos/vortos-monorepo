<?php

declare(strict_types=1);

namespace Vortos\Deploy\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Throwable;
use Vortos\Deploy\Tests\Support\SchemaScenario;

/**
 * Empirically proves the invariant {@see \Vortos\Deploy\Plan\PhaseGate} and {@see
 * \Vortos\Deploy\Plan\DeployPreflightStateBuilder} exist to protect, against a real
 * Postgres database rather than asserting it in the abstract:
 *
 *  - while a migration sits in the `expand` phase, both the old and new application
 *    code paths must keep working against the same table (this is what makes
 *    blue/green — two code versions running concurrently against one shared database —
 *    safe);
 *  - once a migration reaches `contract`, old code is expected to break. That's not a
 *    bug in the contract step; it's the reason the soak gate refuses to let a contract
 *    migration land until no old code can still be running.
 */
final class DualWriteCompatibilityTest extends TestCase
{
    use SchemaScenario;

    public function test_both_colors_read_write_successfully_against_expanded_schema(): void
    {
        $conn = $this->connectToWriteDbOrSkip();
        $table = $this->scenarioTableName();

        $this->createLegacyTable($conn, $table);
        $this->applyExpandPhase($conn, $table);

        try {
            // Old color: still writes name_old, unaware name_new exists.
            $this->oldCodeWrite($conn, $table, 'from-old-color');

            // New color: writes name_new.
            $this->newCodeWrite($conn, $table, 'from-new-color');

            $rows = $conn->fetchAllAssociative("SELECT name_old, name_new FROM {$table} ORDER BY id");

            self::assertCount(2, $rows);
            self::assertSame('from-old-color', $rows[0]['name_old']);
            self::assertNull($rows[0]['name_new']);
            self::assertNull($rows[1]['name_old']);
            self::assertSame('from-new-color', $rows[1]['name_new']);
        } finally {
            $this->dropScenarioTable($conn, $table);
        }
    }

    public function test_old_code_breaks_against_contracted_schema(): void
    {
        $conn = $this->connectToWriteDbOrSkip();
        $table = $this->scenarioTableName();

        $this->createLegacyTable($conn, $table);
        $this->applyExpandPhase($conn, $table);
        $this->applyContractPhase($conn, $table);

        try {
            $threw = false;

            try {
                // Old code still believes name_old exists — it doesn't anymore.
                $this->oldCodeWrite($conn, $table, 'from-old-color-post-contract');
            } catch (Throwable) {
                $threw = true;
            }

            self::assertTrue(
                $threw,
                'Old code writing to a column dropped by the contract phase must fail — '
                . 'this is exactly the breakage the soak gate exists to delay until no old code can be running.',
            );

            // New code is unaffected: it never referenced name_old.
            $this->newCodeWrite($conn, $table, 'from-new-color-post-contract');
            self::assertSame(
                '1',
                (string) $conn->fetchOne("SELECT count(*) FROM {$table} WHERE name_new = 'from-new-color-post-contract'"),
            );
        } finally {
            $this->dropScenarioTable($conn, $table);
        }
    }
}
