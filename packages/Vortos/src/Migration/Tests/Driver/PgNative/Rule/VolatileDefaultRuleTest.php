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

    public function test_flags_constant_default_hot_table_no_snapshot(): void
    {
        $sql = "ALTER TABLE users ADD COLUMN status VARCHAR(20) DEFAULT 'active'";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertStringContainsString('hot table', strtolower($diags[0]->message));
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
}
