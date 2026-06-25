<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Driver\PgNative\Rule;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Driver\PgNative\Rule\BlockingAlterRule;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\TableStat;
use Vortos\Migration\Safety\TargetSchemaSnapshot;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Schema\MigrationPhase;

final class BlockingAlterRuleTest extends TestCase
{
    private BlockingAlterRule $rule;

    protected function setUp(): void
    {
        $this->rule = new BlockingAlterRule();
    }

    public function test_flags_set_not_null(): void
    {
        $sql = "ALTER TABLE users ALTER COLUMN email SET NOT NULL";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertSame(Severity::Error, $diags[0]->severity);
        $this->assertSame('users', $diags[0]->table);
    }

    public function test_flags_alter_column_type(): void
    {
        $sql = "ALTER TABLE users ALTER COLUMN age TYPE BIGINT";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertStringContainsString('TYPE', $diags[0]->message);
    }

    public function test_flags_add_foreign_key_without_not_valid(): void
    {
        $sql = "ALTER TABLE orders ADD CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES users(id)";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertStringContainsString('NOT VALID', $diags[0]->remediation);
    }

    public function test_clean_add_foreign_key_with_not_valid(): void
    {
        $sql = "ALTER TABLE orders ADD CONSTRAINT fk_user FOREIGN KEY (user_id) REFERENCES users(id) NOT VALID";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_flags_add_check_without_not_valid(): void
    {
        $sql = "ALTER TABLE users ADD CONSTRAINT chk_age CHECK (age > 0)";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
    }

    public function test_clean_add_check_with_not_valid(): void
    {
        $sql = "ALTER TABLE users ADD CONSTRAINT chk_age CHECK (age > 0) NOT VALID";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_flags_drop_column_on_hot_table(): void
    {
        $sql = "ALTER TABLE users DROP COLUMN email";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
    }

    public function test_clean_drop_column_on_cold_table(): void
    {
        $sql = "ALTER TABLE users DROP COLUMN email";
        $target = new TargetSchemaSnapshot([
            'users' => new TableStat(estimatedRows: 10, totalBytes: 1024, hasData: true),
        ]);

        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            $target,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_ignores_non_alter_table(): void
    {
        $sql = "CREATE TABLE users (id INT)";
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
