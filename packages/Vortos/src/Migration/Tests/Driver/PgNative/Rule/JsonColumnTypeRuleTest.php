<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Driver\PgNative\Rule;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Driver\PgNative\Rule\JsonColumnTypeRule;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Schema\MigrationPhase;

final class JsonColumnTypeRuleTest extends TestCase
{
    private JsonColumnTypeRule $rule;

    protected function setUp(): void
    {
        $this->rule = new JsonColumnTypeRule();
    }

    public function test_flags_a_json_column_in_create_table(): void
    {
        $diags = $this->evaluate('CREATE TABLE vortos.outbox (id UUID NOT NULL, payload JSON NOT NULL)');

        $this->assertCount(1, $diags);
        $this->assertSame(Severity::Error, $diags[0]->severity);
        $this->assertSame('vortos.outbox', $diags[0]->table);
        $this->assertSame('AllowJsonColumn', $diags[0]->optOutAttribute);
    }

    public function test_flags_a_json_column_added_to_an_existing_table(): void
    {
        $diags = $this->evaluate('ALTER TABLE applications ADD COLUMN IF NOT EXISTS intent JSON DEFAULT NULL');

        $this->assertCount(1, $diags);
        $this->assertSame('applications', $diags[0]->table);
    }

    public function test_flags_a_column_changed_to_json(): void
    {
        $this->assertCount(1, $this->evaluate('ALTER TABLE forms ALTER COLUMN schema TYPE json USING schema::json'));
    }

    public function test_allows_jsonb(): void
    {
        $this->assertCount(0, $this->evaluate('CREATE TABLE outbox (id UUID NOT NULL, payload JSONB NOT NULL)'));
        $this->assertCount(0, $this->evaluate('ALTER TABLE forms ALTER COLUMN schema TYPE JSONB USING schema::jsonb'));
    }

    public function test_ignores_casts_and_functions_that_are_not_column_types(): void
    {
        $this->assertCount(0, $this->evaluate("ALTER TABLE forms ADD COLUMN meta JSONB NOT NULL DEFAULT '{}'::json::jsonb"));
        $this->assertCount(0, $this->evaluate("ALTER TABLE forms ADD COLUMN meta JSONB DEFAULT json_build_object('a', 1)::jsonb"));
    }

    public function test_ignores_statements_that_define_no_columns(): void
    {
        $this->assertCount(0, $this->evaluate("UPDATE forms SET meta = '{}'::json"));
        $this->assertCount(0, $this->evaluate('CREATE INDEX idx_forms_json ON forms (id)'));
    }

    public function test_opt_out_attribute_silences_it(): void
    {
        $sql = 'CREATE TABLE inbox (id UUID NOT NULL, raw JSON NOT NULL)';
        $artifact = new MigrationArtifact('TestMigration', null, MigrationPhase::Expand, [$sql], [], false, false, true);

        $this->assertCount(0, iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 0))));
    }

    /** @return list<\Vortos\Migration\Safety\SafetyDiagnostic> */
    private function evaluate(string $sql): array
    {
        $artifact = new MigrationArtifact('TestMigration', null, MigrationPhase::Expand, [$sql], [], false);

        return array_values(iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 0))));
    }
}
