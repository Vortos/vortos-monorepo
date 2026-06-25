<?php

declare(strict_types=1);

namespace Vortos\Migration\Tests\Driver\PgNative\Rule;

use PHPUnit\Framework\TestCase;
use Vortos\Migration\Driver\PgNative\Rule\NonConcurrentIndexRule;
use Vortos\Migration\Safety\MigrationArtifact;
use Vortos\Migration\Safety\Severity;
use Vortos\Migration\Safety\Rule\ParsedStatement;
use Vortos\Migration\Schema\MigrationPhase;

final class NonConcurrentIndexRuleTest extends TestCase
{
    private NonConcurrentIndexRule $rule;

    protected function setUp(): void
    {
        $this->rule = new NonConcurrentIndexRule();
    }

    public function test_id(): void
    {
        $this->assertSame('pg.index.non-concurrent', $this->rule->id());
    }

    public function test_default_severity_is_error(): void
    {
        $this->assertSame(Severity::Error, $this->rule->defaultSeverity());
    }

    public function test_flags_create_index_without_concurrently(): void
    {
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact(['CREATE INDEX idx_users_email ON users (email)']),
            null,
            new ParsedStatement('CREATE INDEX idx_users_email ON users (email)', 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertSame('pg.index.non-concurrent', $diags[0]->ruleId);
        $this->assertSame('users', $diags[0]->table);
    }

    public function test_flags_create_unique_index_without_concurrently(): void
    {
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact(['CREATE UNIQUE INDEX idx_users_email ON users (email)']),
            null,
            new ParsedStatement('CREATE UNIQUE INDEX idx_users_email ON users (email)', 0),
        ));

        $this->assertCount(1, $diags);
    }

    public function test_clean_create_index_concurrently(): void
    {
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact(['CREATE INDEX CONCURRENTLY idx_users_email ON users (email)']),
            null,
            new ParsedStatement('CREATE INDEX CONCURRENTLY idx_users_email ON users (email)', 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_ignores_non_index_statements(): void
    {
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact(['ALTER TABLE users ADD COLUMN email VARCHAR(255)']),
            null,
            new ParsedStatement('ALTER TABLE users ADD COLUMN email VARCHAR(255)', 0),
        ));

        $this->assertCount(0, $diags);
    }

    public function test_quoted_table_name(): void
    {
        $diags = iterator_to_array($this->rule->evaluate(
            $this->artifact(['CREATE INDEX idx ON "Users" (email)']),
            null,
            new ParsedStatement('CREATE INDEX idx ON "Users" (email)', 0),
        ));

        $this->assertCount(1, $diags);
        $this->assertSame('users', $diags[0]->table);
    }

    /** @param list<string> $sql */
    private function artifact(array $sql): MigrationArtifact
    {
        return new MigrationArtifact('TestMigration', null, MigrationPhase::Expand, $sql, [], false);
    }
}
