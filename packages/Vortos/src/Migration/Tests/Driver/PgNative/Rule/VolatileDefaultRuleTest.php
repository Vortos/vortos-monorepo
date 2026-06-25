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
    private function artifact(array $sql): MigrationArtifact
    {
        return new MigrationArtifact('TestMigration', null, MigrationPhase::Expand, $sql, [], false);
    }
}
