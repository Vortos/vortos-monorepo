<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Driver\PgNative\Rule;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Driver\PgNative\Rule\FullTableRewriteRule;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Schema\MigrationPhase;

final class FullTableRewriteRuleTest extends TestCase
{
    private FullTableRewriteRule $rule;

    protected function setUp(): void
    {
        $this->rule = new FullTableRewriteRule();
    }

    public function test_flags_unbounded_update(): void
    {
        $sql = "UPDATE users SET status = 'active'";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertSame(Severity::Error, $diags[0]->severity);
        $this->assertStringContainsString('UPDATE', $diags[0]->message);
    }

    public function test_flags_unbounded_delete(): void
    {
        $sql = "DELETE FROM old_sessions";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertStringContainsString('DELETE', $diags[0]->message);
    }

    public function test_clean_update_with_where(): void
    {
        $sql = "UPDATE users SET status = 'active' WHERE created_at > '2020-01-01'";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_clean_delete_with_where(): void
    {
        $sql = "DELETE FROM old_sessions WHERE created_at < NOW() - INTERVAL '1 day'";
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact([$sql]),
            null,
            new ParsedStatement($sql, 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_allow_full_table_rewrite_opt_out(): void
    {
        $sql = "UPDATE users SET status = 'active'";
        $artifact = new MigrationArtifact('Test', null, MigrationPhase::Expand, [$sql], [], true);

        $diags = iterator_to_array($this->rule->evaluate($artifact, null, new ParsedStatement($sql, 0)));

        $this->assertCount(0, $diags);
    }

    public function test_ignores_insert(): void
    {
        $sql = "INSERT INTO users (email) SELECT email FROM old_users";
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
