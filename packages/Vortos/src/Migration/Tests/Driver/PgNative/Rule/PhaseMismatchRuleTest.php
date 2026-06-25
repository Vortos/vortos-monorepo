<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Driver\PgNative\Rule;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Driver\PgNative\Rule\PhaseMismatchRule;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Schema\MigrationPhase;

final class PhaseMismatchRuleTest extends TestCase
{
    private PhaseMismatchRule $rule;

    protected function setUp(): void
    {
        $this->rule = new PhaseMismatchRule();
    }

    public function test_flags_drop_table_declared_expand(): void
    {
        $sql = "DROP TABLE old_users";
        $artifact = new MigrationArtifact('Test', null, MigrationPhase::Expand, [$sql], [], false);
        $diags = iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 0)));

        $this->assertCount(1, $diags);
        $this->assertSame(Severity::Error, $diags[0]->severity);
        $this->assertStringContainsString('Contract', $diags[0]->remediation);
    }

    public function test_flags_rename_declared_expand(): void
    {
        $sql = "ALTER TABLE users RENAME TO old_users";
        $artifact = new MigrationArtifact('Test', null, MigrationPhase::Expand, [$sql], [], false);
        $diags = iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 0)));

        $this->assertCount(1, $diags);
    }

    public function test_clean_drop_column_declared_contract(): void
    {
        $sql = "ALTER TABLE users DROP COLUMN email";
        $artifact = new MigrationArtifact('Test', null, MigrationPhase::Contract, [$sql], [], false);
        $diags = iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 0)));

        $this->assertCount(0, $diags);
    }

    public function test_ignores_null_phase(): void
    {
        $sql = "DROP TABLE old_users";
        $artifact = new MigrationArtifact('Test', null, null, [$sql], [], false);
        $diags = iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 0)));

        $this->assertCount(0, $diags);
    }

    public function test_agrees_with_block8_heuristic_on_expand_migration(): void
    {
        $sql = "ALTER TABLE users ADD COLUMN email_new VARCHAR(255) NULL";
        $artifact = new MigrationArtifact('Test', null, MigrationPhase::Expand, [$sql], [], false);
        $diags = iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 0)));

        $this->assertCount(0, $diags);
    }

    public function test_flags_set_not_null_declared_expand(): void
    {
        $sql = "ALTER TABLE users ALTER COLUMN email SET NOT NULL";
        $artifact = new MigrationArtifact('Test', null, MigrationPhase::Expand, [$sql], [], false);
        $diags = iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 0)));

        $this->assertCount(1, $diags);
    }
}
