<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Driver\PgNative\Rule;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Driver\PgNative\Rule\VolatileDefaultRule;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TableStat;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Schema\MigrationPhase;

final class VolatileDefaultRuleTest extends TestCase
{
    private VolatileDefaultRule $rule;

    protected function setUp(): void
    {
        $this->rule = new VolatileDefaultRule(rowThreshold: 100_000, bytesThreshold: 67_108_864);
    }

    public function test_id(): void
    {
        $this->assertSame('pg.column.volatile-default', $this->rule->id());
    }

    public function test_flags_volatile_default_now(): void
    {
        $sql = "ALTER TABLE users ADD COLUMN created_at TIMESTAMP DEFAULT now()";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertSame(Severity::Error, $diags[0]->severity);
        $this->assertStringContainsString('volatile', strtolower($diags[0]->message));
    }

    public function test_flags_volatile_default_gen_random_uuid(): void
    {
        $sql = "ALTER TABLE users ADD COLUMN id UUID DEFAULT gen_random_uuid()";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
    }

    public function test_flags_volatile_default_uuid_generate_v4(): void
    {
        $sql = "ALTER TABLE users ADD COLUMN id UUID DEFAULT uuid_generate_v4()";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
    }

    public function test_clean_constant_default_cold_table(): void
    {
        $sql = "ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'";
        $target = new TargetSchemaSnapshot([
            'users' => new TableStat(estimatedRows: 100, totalBytes: 8192, hasData: true),
        ]);

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            $target,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    /**
     * With no snapshot at all the rule still fails closed — but it now says the size is UNKNOWN
     * rather than asserting a row count it never measured. The old wording ("hot table (>100000
     * rows)") was a fabricated measurement, and reading it about a nine-row table is what taught
     * operators to silence this rule by habit.
     */
    public function test_flags_constant_default_with_no_snapshot_as_undetermined(): void
    {
        $sql = "ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertStringContainsString('could not be determined', $diags[0]->message);
    }

    public function test_flags_constant_default_hot_table_over_row_threshold(): void
    {
        $sql = "ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'";
        $target = new TargetSchemaSnapshot([
            'users' => new TableStat(estimatedRows: 200_000, totalBytes: 1024, hasData: true),
        ]);

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            $target,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
    }

    public function test_allow_full_table_rewrite_opt_out(): void
    {
        $sql = "ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'";
        $artifact = new MigrationArtifact('Test', null, MigrationPhase::Expand, [$sql], [], true);

        $diags = iterator_to_array($this->rule->evaluate(
            $artifact,
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_subselect_is_volatile(): void
    {
        $sql = "ALTER TABLE users ADD COLUMN org_id INT DEFAULT (SELECT 1)";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
    }

    public function test_ignores_non_add_column(): void
    {
        $sql = "ALTER TABLE users DROP COLUMN email";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    /** @param list<string> $sql */
    public function test_a_schema_qualified_table_resolves_to_the_table_not_the_schema(): void
    {
        // THE REGRESSION, verbatim from a blocked production deploy. The table pattern used \w+,
        // which cannot match a dot, so "vortos.alerts_state" yielded "vortos" — the SCHEMA. No
        // statistic exists under that name, and these rules fail closed when statistics are
        // missing, so every schema-qualified migration was reported as touching a hot table and
        // refused, however small it actually was. alerts_state had nine rows.
        $sql = 'ALTER TABLE vortos.alerts_state ADD IF NOT EXISTS reminder_count INT DEFAULT 0 NOT NULL';

        $snapshot = new TargetSchemaSnapshot([
            'vortos.alerts_state' => new TableStat(estimatedRows: 9, totalBytes: 8192, hasData: true),
        ]);

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            $snapshot,
            new ParsedStatement($sql, 0),
        ));

        $this->assertSame([], $diags, 'a nine-row table must not be reported as hot');
    }

    public function test_a_schema_qualified_hot_table_is_still_flagged(): void
    {
        // The other half: resolving the name correctly must not weaken the check. A genuinely large
        // schema-qualified table still fails closed.
        $sql = 'ALTER TABLE vortos.audit_events ADD COLUMN flag INT DEFAULT 0 NOT NULL';

        $snapshot = new TargetSchemaSnapshot([
            'vortos.audit_events' => new TableStat(estimatedRows: 5_000_000, totalBytes: 900_000_000, hasData: true),
        ]);

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            $snapshot,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertStringContainsString('vortos.audit_events', $diags[0]->message);
    }

    private function artifact(array $sql): MigrationArtifact
    {
        return new MigrationArtifact('TestMigration', null, MigrationPhase::Expand, $sql, [], false);
    }

    /**
     * DEFAULT NULL is the absence of a default, spelled out. Postgres stores no default
     * and never rewrites the table, so flagging it pushed authors toward
     * #[AllowFullTableRewrite] for a rewrite that cannot happen — and toward opting out of
     * this rule by habit, including where it is real.
     */
    public function test_ignores_default_null_on_a_hot_table(): void
    {
        $sql = 'ALTER TABLE users ADD COLUMN external_ticket_id VARCHAR(64) DEFAULT NULL';
        $target = new TargetSchemaSnapshot([
            'users' => new TableStat(estimatedRows: 500_000, totalBytes: 1_073_741_824, hasData: true),
        ]);

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            $target,
            new ParsedStatement($sql, 0),
        ));

        $this->assertSame([], $diags);
    }

    public function test_ignores_default_null_when_no_snapshot_is_available(): void
    {
        $sql = 'ALTER TABLE users ADD COLUMN closed_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL';

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertSame([], $diags);
    }

    public function test_still_flags_a_real_constant_default_beside_a_null_one(): void
    {
        $sql = "ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'";

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
    }

    /**
     * PostgreSQL 11 made a constant column DEFAULT metadata-only: no rewrite, no long lock, at any
     * table size. The rule used to flag it regardless and tell the operator to "confirm PG version
     * >= 11" by hand — a question the analyzer is connected to the answer for.
     */
    public function test_constant_default_on_a_hot_table_passes_on_pg11_and_later(): void
    {
        $sql = 'ALTER TABLE vortos.alerts_state ADD COLUMN reminder_count INT DEFAULT 0';

        $target = new TargetSchemaSnapshot(
            ['vortos.alerts_state' => new TableStat(5_000_000, 900_000_000, true)],
            serverVersionNum: 160004,
        );

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            $target,
            new ParsedStatement($sql, 0),
        ));

        $this->assertSame([], $diags);
    }

    /** A volatile default rewrites the table on every version, so 11+ must not excuse it. */
    public function test_volatile_default_is_still_flagged_on_pg11_and_later(): void
    {
        $sql = 'ALTER TABLE vortos.alerts_state ADD COLUMN seen_at TIMESTAMP DEFAULT now()';

        $target = new TargetSchemaSnapshot(
            ['vortos.alerts_state' => new TableStat(5_000_000, 900_000_000, true)],
            serverVersionNum: 160004,
        );

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            $target,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertStringContainsString('volatile', strtolower($diags[0]->message));
    }

    public function test_constant_default_on_a_hot_table_is_flagged_before_pg11(): void
    {
        $sql = 'ALTER TABLE vortos.alerts_state ADD COLUMN reminder_count INT DEFAULT 0';

        $target = new TargetSchemaSnapshot(
            ['vortos.alerts_state' => new TableStat(5_000_000, 900_000_000, true)],
            serverVersionNum: 100_023,
        );

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            $target,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertStringContainsString('rewrites the whole table', $diags[0]->message);
    }

    /**
     * A table with no statistic is UNKNOWN, not big. The rule still fails closed, but it must not
     * report a row count it never measured — claiming ">100000 rows" about a nine-row table is what
     * teaches people to reach for the opt-out attribute by reflex.
     */
    public function test_an_unmeasured_table_is_not_described_as_having_a_row_count(): void
    {
        $sql = 'ALTER TABLE vortos.brand_new ADD COLUMN flag INT DEFAULT 0';

        $target = new TargetSchemaSnapshot([], serverVersionNum: null);

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            $target,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertStringContainsString('could not be determined', $diags[0]->message);
        $this->assertStringNotContainsString('>100000 rows', $diags[0]->message);
    }

    /**
     * The schema qualifier must resolve to the TABLE, not the schema. A \w+ pattern cannot match a
     * dot, so "ALTER TABLE vortos.alerts_state" reported the table as "vortos" — a name with no
     * statistic, which fails closed. Every schema-qualified migration was blocked as "hot".
     */
    public function test_schema_qualified_table_resolves_to_the_table_not_the_schema(): void
    {
        $sql = 'ALTER TABLE vortos.alerts_state ADD COLUMN reminder_count INT DEFAULT 0';

        $target = new TargetSchemaSnapshot([], serverVersionNum: null);

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            $target,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertSame('vortos.alerts_state', $diags[0]->table);
    }
}
